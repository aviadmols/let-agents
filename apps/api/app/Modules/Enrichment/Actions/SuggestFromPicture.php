<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\VisionModel;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Support\FactWriter;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Models\Run;
use GdImage;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A missing detail of how a product looks, suggested from its picture, for a person to approve.
 *
 * Only a detail a picture may tell (VocabularyDefinition::readFromImage: a choice of colour, shape,
 * stone, metal, pattern; never a number or a yes/no). A model that sees pictures looks at the
 * product's main picture and picks from the vocabulary's own values, with how sure it is. Below
 * the vocabulary's bar for that detail nothing is suggested. What it suggests waits for a person
 * (needs_person, "from_picture"); nothing is approved here. The same picture is never asked about
 * the same detail twice.
 */
final class SuggestFromPicture
{
    public const AGENT = 'enrichment.picture_reader';

    public const ACTION = 'enrichment.suggest_from_picture';

    public const PROMPT = 'picture_value';

    public const PROMPT_VERSION = 1;

    public const REASON = 'from_picture';

    /** Products at a press. */
    public const MAX = 50;

    private const OUTPUT_TOKENS = 200;

    /** The longest side sent; enough to see a stone's colour. */
    private const SIDE = 1024;

    /** About this many input tokens for a picture of that size, for the estimate. */
    private const PICTURE_TOKENS = 1600;

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly VisionModel $vision,
        private readonly SpendGuard $spend,
    ) {}

    /** @param list<string> $productIds */
    public function handle(string $shopId, string $vocabularyId, string $attribute, array $productIds): Run
    {
        $productIds = array_slice(array_values(array_unique($productIds)), 0, self::MAX);

        return $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            input: ['attribute' => $attribute, 'products' => count($productIds)],
            work: fn (RunContext $run) => $this->tenant->run($shopId, fn () => $this->suggest($run, $vocabularyId, $attribute, $productIds)),
        );
    }

    /** @param list<string> $productIds */
    private function suggest(RunContext $run, string $vocabularyId, string $attribute, array $productIds): void
    {
        $vocabulary = EnrichmentVocabulary::query()->find($vocabularyId);
        $definition = $vocabulary?->definition();
        $entry = $definition?->attribute($attribute);

        if ($definition === null || $entry === null || ($entry['type'] ?? '') !== 'enum' || ! $definition->readFromImage($attribute)) {
            $run->fail('enrichment::runs.picture_not_visible');

            return;
        }

        $locale = 'he';
        $options = array_map(fn (array $o): array => ['key' => (string) $o['key'], 'label' => (string) ($o['label'][$locale] ?? $o['key'])], (array) ($entry['values'] ?? []));
        $keys = array_column($options, 'key');
        $multiple = $definition->isMultiple($attribute);
        $bar = $definition->imageFilterConfidence($attribute);
        $model = AgentModel::from('enrichment.picture_provider', 'enrichment.picture_model');
        $in = (float) Settings::get('enrichment.picture_input_usd_per_million');
        $out = (float) Settings::get('enrichment.picture_output_usd_per_million');
        $system = self::prompt();
        $counts = ['suggested' => 0, 'unsure' => 0, 'skipped' => 0, 'asked' => 0, 'stopped' => null];

        foreach (CatalogProduct::query()->whereIn('id', $productIds)->whereNull('removed_at')->orderBy('id')->get() as $product) {
            $url = (string) $product->image_url;
            // The picture, the detail, the prompt and the model: unchanged, never asked again.
            $hash = hash('sha256', implode('|', [strtok($url, '?'), $attribute, self::PROMPT_VERSION, $model->model, $vocabulary->definition_hash]));

            if ($url === '' || FactWriter::teamSaid('product_id', $product->id, $attribute)
                || EnrichmentFact::query()->where('product_id', $product->id)->where('key', $attribute)->where('input_hash', $hash)->exists()) {
                $counts['skipped']++;

                continue;
            }

            $picture = self::picture($url);

            if ($picture === null) {
                $counts['skipped']++;

                continue;
            }

            $user = (string) json_encode(['title' => $product->title, 'detail' => (string) ($entry['label'][$locale] ?? $attribute), 'options' => $options, 'multiple' => $multiple], JSON_UNESCAPED_UNICODE);

            try {
                $this->spend->assertCanSpend((self::PICTURE_TOKENS * $in + self::OUTPUT_TOKENS * $out) / 1_000_000);
                $reply = $this->vision->jsonWithImage($model->provider, $model->model, $system, $user, 'image/jpeg', $picture, self::OUTPUT_TOKENS, 'low');
            } catch (SpendCapReached) {
                $counts['stopped'] = 'spend_cap';

                break;
            } catch (ModelCallFailed) {
                $counts['skipped']++;

                continue;
            }

            $counts['asked']++;
            $run->usage($model->provider->value, $model->model, $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($in, $out));

            $values = array_values(array_intersect(array_map('strval', (array) ($reply->data['value'] ?? [])), $keys));
            $values = $multiple ? $values : array_slice($values, 0, 1);
            $sure = (float) ($reply->data['sure'] ?? 0);

            // Unsure, unknown or a value not on the list: the attempt is kept, so it is not asked again, but nothing is suggested.
            if ($values === [] || $sure < $bar) {
                $counts['unsure']++;
                $values = [];
            } else {
                $counts['suggested']++;
            }

            foreach ($values === [] ? [null] : $values as $value) {
                EnrichmentFact::query()->create([
                    'product_id' => $product->id,
                    'vocabulary_id' => $vocabulary->id,
                    'kind' => FactKind::Choice,
                    'key' => $attribute,
                    'value_text' => $value,
                    'quote' => mb_substr($url, 0, 500),
                    'origin' => FactOrigin::Model,
                    'status' => $value === null ? FactStatus::Rejected : FactStatus::NeedsPerson,
                    'status_reason' => $value === null ? 'picture_unsure' : self::REASON,
                    'input_hash' => $hash,
                    'model' => $model->model,
                ]);
            }
        }

        $run->output($counts + ['model' => $model->model, 'bar' => $bar])->summary('enrichment::runs.picture_suggested', [
            'suggested' => (string) $counts['suggested'],
            'unsure' => (string) $counts['unsure'],
        ]);
    }

    /** The picture, upright and no larger than a model needs, as JPEG; null when it cannot be read. */
    private static function picture(string $url): ?string
    {
        try {
            $response = Http::timeout(20)->connectTimeout(5)->get($url);
        } catch (Throwable) {
            return null;
        }

        $image = $response->successful() ? @imagecreatefromstring($response->body()) : false;

        if (! $image instanceof GdImage) {
            return null;
        }

        $scale = self::SIDE / max(imagesx($image), imagesy($image));
        if ($scale < 1) {
            $image = imagescale($image, (int) round(imagesx($image) * $scale), (int) round(imagesy($image) * $scale), IMG_BICUBIC) ?: $image;
        }

        ob_start();
        imagejpeg($image, null, 85);

        return (string) ob_get_clean();
    }

    public static function prompt(): string
    {
        return (string) file_get_contents(dirname(__DIR__).'/Prompts/'.self::PROMPT.'.v'.self::PROMPT_VERSION.'.md');
    }
}
