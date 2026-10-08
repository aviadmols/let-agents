<?php

namespace App\Modules\Retrieval\Filament\Operator\Pages;

use App\Core\Facades\Settings;
use App\Core\Tenancy\NeedsShopContext;
use App\Core\Tenancy\TenantContext;
use App\Modules\Retrieval\Actions\BuildImageIndex;
use App\Modules\Retrieval\Actions\BuildIndex;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Runs\Models\Run;
use BackedEnum;
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
final class ScanHistory extends Page
{
    use NeedsShopContext;
    use WithPagination;

    public const STATES = ['all', 'scanned', 'pending', 'failed'];

    public const REASONS = ['unreachable', 'not_image', 'too_big'];

    private const RUNS = 40;

    private const PER_PAGE = 50;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = 'content';

    protected static ?int $navigationSort = 91;

    protected static ?string $slug = 'retrieval/scans';

    protected string $view = 'retrieval::operator.scan-history';

    #[Url]
    public string $state = 'all';

    #[Url]
    public string $search = '';

    public static function getNavigationLabel(): string
    {
        return __('retrieval::ui.scans.title');
    }

    public function getTitle(): string
    {
        return __('retrieval::ui.scans.title');
    }

    public function getSubheading(): ?string
    {
        return __('retrieval::ui.scans.subheading');
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
            ->paginate(self::PER_PAGE, ['id', 'external_id', 'title', 'image_url', 'embedding_model', 'error', 'embedded_at']);
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
