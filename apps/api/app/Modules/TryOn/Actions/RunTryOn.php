<?php

namespace App\Modules\TryOn\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ImageModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\VisionModel;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\TryOn\Models\TryOnJob;
use App\Modules\TryOn\Models\TryOnStep;
use App\Modules\TryOn\Support\Files;
use App\Modules\TryOn\Support\Picture;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * One try, in the background (docs/try-on.md §3, steps 3 to 5): the prompt (the team's template for
 * the kind of product, made exact for this product, this photo and the shopper's answers by a writer
 * model), the picture (an image model on Replicate), and the check (a model of another family looks
 * at the product, the photo and the result side by side). What the checker refuses is tried again
 * with its correction, up to the retries and the cost cap of one try; a picture that never passes is
 * not shown.
 *
 * Every step is kept: what went in, the prompt as sent, what came back, the pictures, the model, the
 * tokens, the time, the cost. Every call asks SpendGuard first and is accounted on the run.
 */
final class RunTryOn
{
    public const AGENT = 'try_on.maker';

    public const ACTION = 'try_on.make';

    private const WRITER_OUTPUT_TOKENS = 600;

    private const CHECKER_OUTPUT_TOKENS = 600;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly ChatModel $chat,
        private readonly VisionModel $vision,
        private readonly ImageModel $images,
        private readonly SpendGuard $spend,
    ) {}

    public function handle(string $shopId, string $jobId): void
    {
        $this->tenant->run($shopId, function () use ($shopId, $jobId): void {
            $job = TryOnJob::query()->with(['photo', 'product'])->find($jobId);

            if ($job === null || $job->status !== TryOnJob::QUEUED) {
                return;
            }

            $job->update(['status' => TryOnJob::WORKING, 'stage' => 'prompt']);

            $run = $this->runs->track(
                agent: self::AGENT,
                action: self::ACTION,
                shopId: $shopId,
                work: fn (RunContext $run) => $this->make($run, $job),
            );

            $job->update(['run_id' => $run->id]);
        });
    }

    private function make(RunContext $run, TryOnJob $job): void
    {
        $writer = AgentModel::from('try_on.writer_provider', 'try_on.writer_model');
        $checker = AgentModel::from('try_on.checker_provider', 'try_on.checker_model');
        $generator = AiProviderName::tryFrom((string) Settings::get('try_on.generator_provider')) ?? AiProviderName::Replicate;
        $model = (string) Settings::get('try_on.model_'.$job->group);

        // What one family writes, another checks.
        if ($writer->provider->family() === $checker->provider->family()) {
            $this->finish($run, $job, 'same_family');

            return;
        }

        $photo = Files::get($job->photo->path);
        $productPicture = $this->productPicture((string) $job->productImage());

        if ($photo === null || $productPicture === null) {
            $this->finish($run, $job, $photo === null ? 'photo_gone' : 'no_product_picture');

            return;
        }

        $inputs = [['mime' => 'image/jpeg', 'bytes' => Picture::forModel($photo)], ['mime' => 'image/jpeg', 'bytes' => Picture::forModel($productPicture)]];
        $productPath = Files::put($job->shop_id, 'product', $inputs[1]['bytes']);
        $cap = (float) Settings::get('try_on.job_cap_usd');
        // Two at most, whatever the setting says.
        $tries = min(TryOnJob::MAX_ATTEMPTS, 1 + (int) Settings::get('try_on.retries'));
        $fix = '';
        $cost = 0.0;

        for ($attempt = 1; $attempt <= $tries; $attempt++) {
            // Ended while it worked (past its minutes): nothing more is made or paid for.
            if ($job->fresh()?->status === TryOnJob::FAILED) {
                return;
            }
            $job->update(['attempts' => $attempt, 'stage' => 'prompt']);

            try {
                $estimate = $this->estimateWriter($writer) + (float) Settings::get('try_on.generation_usd_per_image') + $this->estimateChecker();
                if ($cost + $estimate > $cap) {
                    $this->finish($run, $job, 'job_cap', $cost);

                    return;
                }
                $this->spend->assertCanSpend($estimate, true);

                // 1. The prompt.
                $input = $this->writerInput($job, $fix);
                $system = self::prompt('tryon_writer');
                $reply = $this->chat->json($writer->provider, $writer->model, $system, (string) json_encode($input, JSON_UNESCAPED_UNICODE), self::WRITER_OUTPUT_TOKENS, 'low');
                $prompt = trim((string) ($reply->data['prompt'] ?? '')) ?: $input['template'];
                $spent = $reply->costUsd($this->price('writer', 'input'), $this->price('writer', 'output'));
                $cost += $spent;
                $run->usage($writer->provider->value, $writer->model, $reply->inputTokens, $reply->outputTokens, 0, $spent);
                $this->step($job, 'prompt', $attempt, 'done', ['input' => $input, 'output' => ['prompt' => $prompt], 'prompt' => $system, 'provider' => $writer->provider->value, 'model' => $writer->model,
                    'tokens_in' => $reply->inputTokens, 'tokens_out' => $reply->outputTokens, 'cost_usd' => $spent]);

                // 2. The picture.
                $job->update(['stage' => 'generate']);
                $made = $this->images->generate($generator, $model, $prompt, $inputs);
                $spent = (float) Settings::get('try_on.generation_usd_per_image');
                $cost += $spent;
                $run->usage($generator->value, $model, 0, 0, 0, $spent);
                $resultPath = Files::put($job->shop_id, 'result', Picture::forModel($made->bytes, Picture::KEEP_SIDE));
                $this->step($job, 'generate', $attempt, 'done', ['input' => ['model' => $model, 'pictures' => ['photo', 'product']], 'output' => ['prediction' => $made->predictionId], 'prompt' => $prompt,
                    'provider' => $generator->value, 'model' => $model, 'seconds' => $made->seconds, 'cost_usd' => $spent, 'images' => ['photo' => $job->photo->path, 'product' => $productPath, 'result' => $resultPath]]);

                // 3. The check, by another family, on the three side by side.
                $job->update(['stage' => 'check']);
                $sheet = Picture::sideBySide([$inputs[1]['bytes'], $inputs[0]['bytes'], Picture::forModel($made->bytes)]);
                $sheetPath = Files::put($job->shop_id, 'sheet', $sheet);
                $system = self::prompt('tryon_checker');
                $checked = $this->vision->jsonWithImage($checker->provider, $checker->model, $system, (string) json_encode(['answers' => $job->answers ?? (object) []]), 'image/jpeg', $sheet, self::CHECKER_OUTPUT_TOKENS, 'low');
                $spent = $checked->costUsd($this->price('checker', 'input'), $this->price('checker', 'output'));
                $cost += $spent;
                $run->usage($checker->provider->value, $checker->model, $checked->inputTokens, $checked->outputTokens, 0, $spent);
                [$passed, $why] = $this->passes($checked->data);
                $this->step($job, 'check', $attempt, $passed ? 'passed' : 'refused', ['output' => $checked->data + ['decision' => $passed ? 'pass' : $why], 'prompt' => $system,
                    'provider' => $checker->provider->value, 'model' => $checker->model, 'tokens_in' => $checked->inputTokens, 'tokens_out' => $checked->outputTokens, 'cost_usd' => $spent, 'images' => ['sheet' => $sheetPath, 'result' => $resultPath]]);
            } catch (SpendCapReached) {
                $this->finish($run, $job, 'busy', $cost);

                return;
            } catch (ModelCallFailed $e) {
                $this->step($job, (string) $job->stage, $attempt, 'failed', ['error' => $e->reason]);
                $this->finish($run, $job, 'model_failed', $cost);

                return;
            }

            if ($passed) {
                $job->update(['status' => TryOnJob::READY, 'stage' => null, 'result_path' => $resultPath, 'cost_usd' => round($cost, 6), 'finished_at' => now()]);
                $run->output(['job' => $job->id, 'attempts' => $attempt, 'cost' => round($cost, 6)])->summary('try_on::runs.ready', ['product' => mb_substr($job->productTitle(), 0, 60), 'attempts' => (string) $attempt]);

                return;
            }

            $fix = trim((string) ($checked->data['fix'] ?? '')) ?: $why;
        }

        $this->finish($run, $job, 'check_failed', $cost);
    }

    /**
     * Whether the checker's answer lets the picture be shown: the product itself, the same person or
     * room, a natural body, a real size, the place asked for, and nobody added.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: bool, 1: string}
     */
    public function passes(array $data): array
    {
        $why = match (true) {
            // A new picture instead of the shopper's own, or the product not there: always refused.
            ($data['same_photo'] ?? true) !== true => 'not_the_same_photo',
            ($data['product_visible'] ?? true) !== true => 'product_not_visible',
            (int) ($data['product_similarity'] ?? 0) < (int) Settings::get('try_on.pass_similarity') => 'not_the_product',
            ($data['identity_kept'] ?? false) !== true => 'not_the_same_person',
            ($data['anatomy_ok'] ?? false) !== true => 'anatomy',
            ($data['proportions_ok'] ?? false) !== true => 'proportions',
            ($data['placement_ok'] ?? true) !== true => 'placement',
            ($data['extra_people'] ?? false) === true => 'extra_people',
            default => '',
        };

        return [$why === '', $why];
    }

    /** @return array<string, mixed> */
    private function writerInput(TryOnJob $job, string $fix): array
    {
        $facts = $job->product_id === null ? collect() : EnrichmentFact::query()->with('vocabulary')->where('product_id', $job->product_id)->whereIn('kind', [FactKind::Choice, FactKind::Spec])
            ->where('status', FactStatus::Approved)->orderBy('key')->get();
        $details = [];
        $dimensions = [];
        foreach ($facts as $fact) {
            $label = $fact->keyLabel().': '.$fact->valueLabel();
            $fact->kind === FactKind::Spec && in_array($fact->unit, ['mm', 'kg', 'ct'], true) ? $dimensions[] = $label : $details[] = $label;
        }

        return array_filter([
            'template' => trim(self::prompt('tryon_'.$job->group)),
            'product' => ['title' => $job->productTitle(), 'class' => $job->tryon_class, 'details' => array_slice($details, 0, 12), 'dimensions' => array_slice($dimensions, 0, 6)],
            'photo' => array_intersect_key((array) $job->photo->gate, array_flip(['subject', 'parts_visible', 'scale', 'free_spots', 'hands_visible'])),
            'answers' => $job->answers ?? [],
            'fix' => $fix,
        ], fn ($value): bool => $value !== '' && $value !== null);
    }

    private function productPicture(string $url): ?string
    {
        if (! preg_match('#^https?://#i', $url)) {
            return null;
        }

        try {
            $response = Http::timeout(20)->get($url);
        } catch (Throwable) {
            return null;
        }

        return $response->successful() && $response->body() !== '' ? $response->body() : null;
    }

    /** @param  array<string, mixed>  $values */
    private function step(TryOnJob $job, string $step, int $attempt, string $status, array $values): void
    {
        TryOnStep::query()->create(['shop_id' => $job->shop_id, 'job_id' => $job->id, 'photo_id' => $job->photo_id, 'step' => $step, 'attempt' => $attempt, 'status' => $status]
            + array_intersect_key($values, array_flip(['input', 'output', 'prompt', 'provider', 'model', 'tokens_in', 'tokens_out', 'seconds', 'cost_usd', 'images', 'error'])));
    }

    private function finish(RunContext $run, TryOnJob $job, string $failure, float $cost = 0.0): void
    {
        $job->update(['status' => TryOnJob::FAILED, 'failure' => $failure, 'cost_usd' => round($cost, 6), 'finished_at' => now()]);
        $run->output(['job' => $job->id, 'failure' => $failure, 'cost' => round($cost, 6)])->fail('try_on::runs.failed', ['product' => mb_substr($job->productTitle(), 0, 60), 'why' => $failure], $failure);
    }

    private function estimateWriter(AgentModel $writer): float
    {
        return (2500 * $this->price('writer', 'input') + self::WRITER_OUTPUT_TOKENS * $this->price('writer', 'output')) / 1_000_000;
    }

    private function estimateChecker(): float
    {
        return (2500 * $this->price('checker', 'input') + self::CHECKER_OUTPUT_TOKENS * $this->price('checker', 'output')) / 1_000_000;
    }

    private function price(string $role, string $side): float
    {
        return (float) Settings::get("try_on.{$role}_{$side}_usd_per_million");
    }

    public static function prompt(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__).'/Prompts/'.$name.'.v'.(StartTryOn::PROMPT_VERSIONS[str_starts_with($name, 'tryon_writer') ? 'writer' : (str_starts_with($name, 'tryon_checker') ? 'checker' : 'template')]).'.md');
    }
}
