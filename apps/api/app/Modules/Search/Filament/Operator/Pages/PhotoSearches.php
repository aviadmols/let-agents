<?php

namespace App\Modules\Search\Filament\Operator\Pages;

use App\Core\Tenancy\TenantContext;
use App\Modules\Search\Jobs\CheckPhotoSearchesJob;
use App\Modules\Search\Models\SearchPhotoAsk;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * The photos shoppers searched with in one shop, what was seen in each and what they got, for
 * the team to say right or wrong. The marked photos are the set photo search is measured against:
 * "check now" reads them again with the reader as it is today and counts how many it gets.
 */
final class PhotoSearches extends Page
{
    private const ROWS = 40;

    private const DAYS = 30;

    /** A check asked for this long ago and not finished is shown as running. */
    private const RUNNING_SECONDS = 1200;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCamera;

    protected static string|UnitEnum|null $navigationGroup = 'discovery';

    protected static ?int $navigationSort = 11;

    protected static ?string $slug = 'search/photos';

    protected string $view = 'search::operator.photo-searches';

    #[Url]
    public ?string $shop = null;

    /** all, unmarked, wrong or marked. */
    #[Url]
    public string $show = 'all';

    /** @var array<string, string> what each photo should have found, as the team types it */
    public array $expected = [];

    public static function getNavigationLabel(): string
    {
        return __('search::ui.photo_log.title');
    }

    public function getTitle(): string
    {
        return __('search::ui.photo_log.title');
    }

    public function getSubheading(): ?string
    {
        return __('search::ui.photo_log.subheading');
    }

    public function mount(): void
    {
        $this->shop ??= app(TenantContext::class)->id() ?? Shop::query()->orderBy('name')->value('id');
    }

    /** @return array<string, string> */
    public function shops(): array
    {
        return Shop::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** @return list<SearchPhotoAsk> newest first */
    public function asks(): array
    {
        if ($this->shop === null) {
            return [];
        }

        return app(TenantContext::class)->run($this->shop, fn (): array => SearchPhotoAsk::query()
            ->when($this->show === 'unmarked', fn ($q) => $q->whereNull('verdict'))
            ->when($this->show === 'wrong', fn ($q) => $q->where('verdict', SearchPhotoAsk::WRONG))
            ->when($this->show === 'marked', fn ($q) => $q->whereNotNull('verdict'))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(self::ROWS)
            ->get()
            ->all());
    }

    /** @return array<string, mixed>|null */
    public function summary(): ?array
    {
        if ($this->shop === null) {
            return null;
        }

        return app(TenantContext::class)->run($this->shop, function (): array {
            $marked = SearchPhotoAsk::query()->whereNotNull('verdict');
            $checked = SearchPhotoAsk::query()->whereNotNull('checked_at');
            $asked = Cache::get(CheckPhotoSearchesJob::RUNNING.$this->shop);

            return [
                'asks' => SearchPhotoAsk::query()->where('created_at', '>=', now()->subDays(self::DAYS))->count(),
                'nothing' => SearchPhotoAsk::query()->where('created_at', '>=', now()->subDays(self::DAYS))->where('total', 0)->count(),
                'marked' => (clone $marked)->count(),
                'right' => (clone $marked)->where('verdict', SearchPhotoAsk::RIGHT)->count(),
                'checked' => (clone $checked)->count(),
                'checked_right' => (clone $checked)->where('checked_right', true)->count(),
                'checked_at' => (clone $checked)->max('checked_at'),
                'running' => is_int($asked) && now()->timestamp - $asked < self::RUNNING_SECONDS,
            ];
        });
    }

    public function markRight(string $id): void
    {
        $this->mark($id, SearchPhotoAsk::RIGHT, null);
    }

    public function markWrong(string $id): void
    {
        $expected = trim((string) ($this->expected[$id] ?? ''));

        if ($expected === '') {
            Notification::make()->warning()->title(__('search::ui.photo_log.say_what'))->send();

            return;
        }

        $this->mark($id, SearchPhotoAsk::WRONG, mb_substr($expected, 0, 120));
    }

    public function unmark(string $id): void
    {
        $this->mark($id, null, null);
    }

    /** Reads every marked photo again with the reader as it is now, on the queue. */
    public function checkNow(): void
    {
        abort_if($this->shop === null, 404);

        if ((bool) ($this->summary()['running'] ?? false)) {
            Notification::make()->warning()->title(__('search::ui.photo_log.check_wait'))->send();

            return;
        }

        Cache::put(CheckPhotoSearchesJob::RUNNING.$this->shop, now()->timestamp, now()->addSeconds(self::RUNNING_SECONDS));
        CheckPhotoSearchesJob::dispatch($this->shop);
        Notification::make()->success()->title(__('search::ui.photo_log.check_started'))->send();
    }

    private function mark(string $id, ?string $verdict, ?string $expected): void
    {
        abort_if($this->shop === null, 404);

        app(TenantContext::class)->run($this->shop, function () use ($id, $verdict, $expected): void {
            SearchPhotoAsk::query()->whereKey($id)->update([
                'verdict' => $verdict,
                'expected' => $expected,
                'marked_at' => $verdict === null ? null : now(),
                'checked_main' => null,
                'checked_right' => null,
                'checked_at' => null,
            ]);
        });

        unset($this->expected[$id]);
    }
}
