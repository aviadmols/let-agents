<?php

namespace App\Modules\Retrieval\Filament\Operator\Pages;

use App\Core\Facades\Settings;
use App\Core\Tenancy\NeedsShopContext;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Retrieval\Actions\BuildImageIndex;
use App\Modules\Retrieval\Actions\BuildIndex;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Retrieval\Support\VectorSearch;
use App\Modules\Runs\Models\Run;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use UnitEnum;

/**
 * One shop's scans, in order: every catalogue sync, text index and picture scan with what it
 * did, and below them every picture of the shop, scanned, waiting or unreadable and why.
 */
class ScanHistory extends Page
{
    use NeedsShopContext;
    use WithPagination;

    public const STATES = ['all', 'scanned', 'pending', 'failed'];

    public const REASONS = ['unreachable', 'not_image', 'too_big'];

    private const RUNS = 40;

    private const PER_PAGE = 50;

    private const NEAREST = 8;

    private const PER_GALLERY = 48;

    /** Cells in a gallery card's vector strip. */
    private const MINI_STRIP = 32;

    private const STRIP = 64;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = 'content';

    protected static ?int $navigationSort = 91;

    protected static ?string $slug = 'retrieval/scans';

    protected string $view = 'retrieval::operator.scan-history';

    #[Url]
    public string $state = 'all';

    #[Url]
    public string $search = '';

    /** "table" or "gallery": the pictures as rows, or as a grid with their vectors. */
    #[Url]
    public string $display = 'table';

    /** The shop, in the shop's own panel (set from the address there). */
    public ?string $shop = null;

    /** The picture whose vector is open for inspection. */
    #[Url]
    public ?string $inspect = null;

    public static function getNavigationLabel(): string
    {
        return __('retrieval::ui.scans.'.(static::forOperator() ? 'title' : 'shop_title'));
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function getSubheading(): ?string
    {
        return __('retrieval::ui.scans.'.(static::forOperator() ? 'subheading' : 'shop_subheading'));
    }

    /**
     * The operator sees runs, vectors and models; the shop sees its pictures and what was seen in
     * them, nothing about how.
     */
    public function operatorView(): bool
    {
        return static::forOperator();
    }

    protected static function forOperator(): bool
    {
        return Filament::getCurrentPanel()?->getId() === User::OPERATOR_PANEL;
    }

    public function updatedState(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    /** @return Collection<int, Run> the shop's syncs and scans, newest first */
    public function runs(): Collection
    {
        return Run::query()
            ->where('shop_id', app(TenantContext::class)->id())
            ->whereIn('agent', ['catalog.syncer', BuildIndex::AGENT, BuildImageIndex::AGENT])
            ->latest('started_at')
            ->limit(self::RUNS)
            ->get();
    }

    /** @return array{total: int, scanned: int, pending: int, failed: array<string, int>} */
    public function counts(): array
    {
        $failed = RetrievalImage::query()->whereNotNull('error')
            ->selectRaw('error, count(*) as n')->groupBy('error')->pluck('n', 'error')
            ->map(fn ($n): int => (int) $n)->all();
        ksort($failed);

        return [
            'total' => RetrievalImage::query()->count(),
            'scanned' => $this->scanned(RetrievalImage::query())->count(),
            'pending' => $this->pending(RetrievalImage::query())->count(),
            'failed' => $failed,
        ];
    }

    /** @return LengthAwarePaginator<int, RetrievalImage> */
    public function images(): LengthAwarePaginator
    {
        $query = RetrievalImage::query();
        $term = trim($this->search);

        if ($term !== '') {
            $query->where(fn ($q) => $q->where('title', 'like', "%{$term}%")->orWhere('external_id', $term));
        }

        match ($this->state) {
            'scanned' => $this->scanned($query),
            'pending' => $this->pending($query),
            'failed' => $query->whereNotNull('error'),
            default => null,
        };

        return $query->orderByRaw('embedded_at is null')->orderByDesc('embedded_at')->orderBy('title')
            ->paginate($this->display === 'gallery' ? self::PER_GALLERY : self::PER_PAGE, array_merge(
                ['id', 'product_id', 'external_id', 'title', 'image_url', 'embedding_model', 'error', 'embedded_at', 'caption', 'caption_words'],
                // The gallery draws each picture's vector, so it reads them; the table does not.
                $this->display === 'gallery' ? ['embedding'] : [],
            ));
    }

    public function toggleInspect(string $imageId): void
    {
        $this->inspect = $this->inspect === $imageId ? null : $imageId;
    }

    /**
     * One picture's vector, readable: its model and size, its length, a few raw values, a strip of
     * the whole vector, and the shop's pictures nearest to it. Nearest pictures that look alike
     * are the proof the scan works; nearest pictures that do not are the sign it does not.
     *
     * @return array{image: RetrievalImage, model: string, dimensions: int, norm: float, min: float, max: float, head: list<float>, strip: list<float>, nearest: list<array<string, mixed>>}|null
     */
    public function inspection(): ?array
    {
        $image = $this->inspect === null ? null : RetrievalImage::query()->find($this->inspect);
        $vector = $image?->vector();

        if ($image === null || $vector === null) {
            return null;
        }

        $nearest = app(VectorSearch::class)->lookAlike((string) $image->product_id, self::NEAREST);
        $urls = RetrievalImage::query()->whereIn('external_id', array_column($nearest, 'external_id'))->pluck('image_url', 'external_id');

        return [
            'image' => $image,
            'caption' => $image->caption,
            'words' => (array) $image->caption_words,
            'model' => (string) $image->embedding_model,
            'dimensions' => count($vector),
            'norm' => round(sqrt(array_sum(array_map(fn (float $v): float => $v * $v, $vector))), 4),
            'min' => round(min($vector), 4),
            'max' => round(max($vector), 4),
            'head' => array_map(fn (float $v): float => round($v, 4), array_slice($vector, 0, 12)),
            'strip' => self::strip($vector, self::STRIP),
            'nearest' => array_map(fn (array $hit): array => $hit + ['image_url' => $urls[$hit['external_id']] ?? null], $nearest),
        ];
    }

    /**
     * A picture's vector as a short strip for the gallery: empty for a picture not scanned.
     *
     * @return list<float>
     */
    public function miniStrip(RetrievalImage $image): array
    {
        $vector = $image->vector();

        return $vector === null ? [] : self::strip($vector, self::MINI_STRIP);
    }

    public function updatedDisplay(): void
    {
        $this->resetPage();
    }

    /**
     * The vector squeezed into a few cells, each the mean of its stretch scaled to -1…1, so two
     * pictures' vectors can be compared by eye.
     *
     * @param  list<float>  $vector
     * @return list<float>
     */
    private static function strip(array $vector, int $cells): array
    {
        $size = max(1, (int) ceil(count($vector) / $cells));
        $means = array_map(fn (array $chunk): float => array_sum($chunk) / count($chunk), array_chunk($vector, $size));
        $peak = max(array_map('abs', $means)) ?: 1.0;

        return array_map(fn (float $m): float => round($m / $peak, 3), $means);
    }

    /** A run's state as shown: one still "running" after half an hour was cut off by the worker. */
    public static function runState(Run $run): string
    {
        $state = $run->status->value;

        return $state === 'running' && $run->started_at?->lt(now()->subMinutes(30)) ? 'cut_off' : $state;
    }

    /**
     * Where the shop's pictures are, host by host, most first: a store that moved shows here
     * whether its pictures moved with it.
     *
     * @return array<string, int>
     */
    public function hosts(): array
    {
        $hosts = [];

        foreach (RetrievalImage::query()->pluck('image_url') as $url) {
            $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
            $hosts[$host] = ($hosts[$host] ?? 0) + 1;
        }

        arsort($hosts);

        return $hosts;
    }

    /** Scanned, waiting or unreadable: a key under retrieval::ui.scans.state. */
    public function stateOf(RetrievalImage $image): string
    {
        return match (true) {
            $image->error !== null => 'failed',
            $image->embedded_at !== null && $image->embedding_model === $this->model() => 'scanned',
            default => 'pending',
        };
    }

    /** Why a picture could not be read: a key under retrieval::ui.scans.reasons. */
    public static function reason(?string $error): string
    {
        return in_array($error, self::REASONS, true) ? $error : 'other';
    }

    /** @param Builder<RetrievalImage> $query */
    private function scanned(Builder $query): Builder
    {
        return $query->whereNotNull('embedded_at')->whereNull('error')->where('embedding_model', $this->model());
    }

    /** @param Builder<RetrievalImage> $query */
    private function pending(Builder $query): Builder
    {
        return $query->whereNull('error')->where(fn ($q) => $q->whereNull('embedded_at')->orWhereNull('embedding_model')->orWhere('embedding_model', '!=', $this->model()));
    }

    private function model(): string
    {
        return (string) Settings::get('retrieval.image_model');
    }
}
