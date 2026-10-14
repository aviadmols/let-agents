<?php

namespace App\Modules\TryOn\Filament\Operator\Pages;

use App\Core\Tenancy\NeedsShopContext;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\TryOn\Models\TryOnJob;
use App\Modules\TryOn\Models\TryOnPhoto;
use App\Modules\TryOn\Models\TryOnStep;
use App\Modules\TryOn\Support\Files;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The shop's Try-On, as it happened (docs/try-on.md §9): the latest tries with their status, attempts
 * and cost; one try opened shows every step in order, with its prompt as sent, its answer, its
 * pictures (the photo, the product, each picture made and the sheet the checker saw), the model,
 * the tokens and the cost; and the photo checks, refused ones too. The operator's only.
 */
final class TryOnLog extends Page
{
    use NeedsShopContext;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'content';

    protected static ?int $navigationSort = 96;

    protected static ?string $slug = 'try-on/log';

    protected string $view = 'try_on::operator.log';

    #[Url]
    public ?string $job = null;

    public static function getNavigationLabel(): string
    {
        return __('try_on::try_on.log.title');
    }

    public function getTitle(): string
    {
        return __('try_on::try_on.log.title');
    }

    public function getSubheading(): ?string
    {
        return __('try_on::try_on.log.subheading');
    }

    /** @return Collection<int, TryOnJob> */
    public function jobs(): Collection
    {
        return $this->inShop(fn () => TryOnJob::query()->with('product:id,title')->latest()->limit(50)->get());
    }

    /** @return array{count: int, ready: int, usd: string} */
    public function month(): array
    {
        return $this->inShop(function (): array {
            $jobs = TryOnJob::query()->where('created_at', '>=', now()->startOfMonth());
            $photos = (float) TryOnPhoto::query()->where('created_at', '>=', now()->startOfMonth())->sum('cost_usd');

            return ['count' => (clone $jobs)->count(), 'ready' => (clone $jobs)->where('status', TryOnJob::READY)->count(), 'usd' => number_format((float) $jobs->sum('cost_usd') + $photos, 4)];
        }) ?? ['count' => 0, 'ready' => 0, 'usd' => '0'];
    }

    /**
     * How the team tries Try-On on its own store before it is on for shoppers: the parameters to add
     * to any product page's address, with this store's preview key.
     *
     * @return array{params: string, example: string|null}|null
     */
    public function testLink(): ?array
    {
        return $this->inShop(function (): ?array {
            $connection = StoreConnection::query()->first();

            if ($connection === null) {
                return null;
            }

            $params = 'let_agents_preview='.$connection->previewKey().'&let_agents_tryon=1';

            return ['params' => $params, 'example' => $connection->site_url ? rtrim((string) $connection->site_url, '/').'/?'.$params : null];
        });
    }

    public function open(string $id): void
    {
        $this->job = $this->job === $id ? null : $id;
    }

    /**
     * The open try, with the photo's check and its own steps in order, and each picture as a signed address.
     *
     * @return array{job: TryOnJob, steps: list<array<string, mixed>>, photo: string|null, result: string|null}|null
     */
    public function current(): ?array
    {
        if ($this->job === null) {
            return null;
        }

        return $this->inShop(function (): ?array {
            $job = TryOnJob::query()->with(['product:id,title,image_url', 'photo'])->find($this->job);

            if ($job === null) {
                return null;
            }

            $steps = TryOnStep::query()->where(fn ($q) => $q->where('job_id', $job->id)->orWhere(fn ($p) => $p->whereNull('job_id')->where('photo_id', $job->photo_id)))
                ->orderBy('created_at')->orderBy('id')->get()
                ->map(fn (TryOnStep $s): array => [
                    'step' => $s->step, 'attempt' => $s->attempt, 'status' => $s->status, 'provider' => $s->provider, 'model' => $s->model,
                    'tokens' => $s->tokens_in.' / '.$s->tokens_out, 'seconds' => (float) $s->seconds, 'cost' => (float) $s->cost_usd,
                    'prompt' => $s->prompt, 'input' => $s->input, 'output' => $s->output, 'error' => $s->error,
                    'images' => array_map(fn (string $path): ?string => Files::url($path, 30), (array) ($s->images ?? [])),
                ])->all();

            return ['job' => $job, 'steps' => $steps, 'photo' => Files::url($job->photo?->path, 30), 'result' => Files::url($job->result_path, 30)];
        });
    }

    private function inShop(callable $callback): mixed
    {
        $shop = app(TenantContext::class)->id();

        return $shop === null ? null : app(TenantContext::class)->run($shop, $callback);
    }
}
