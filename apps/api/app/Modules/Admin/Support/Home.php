<?php

namespace App\Modules\Admin\Support;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\HomeNumbers;
use App\Modules\Admin\Contracts\HomeSource;
use App\Modules\Admin\Contracts\HomeStatus;
use App\Modules\Admin\Contracts\ModuleFigure;
use App\Modules\Admin\Contracts\ShopNotice;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What the shop's home and notices show, gathered from every module that tagged a HomeSource
 * ('admin.home'), each asked inside the shop. A module that fails is left out and reported, never
 * the page.
 */
final class Home
{
    public const TAG = HomeSource::TAG;

    /** Statuses and notices are counted queries; a few minutes old is fresh enough for a home. */
    private const CACHE_SECONDS = 120;

    public function __construct(private readonly TenantContext $tenant) {}

    /** @return list<HomeStatus> */
    public function statuses(Shop $shop): array
    {
        // The cache keeps plain arrays (it unserializes no classes); the objects are made again here.
        $rows = $this->remember($shop, 'statuses', fn (HomeSource $source): array => array_map(fn (HomeStatus $s): array => get_object_vars($s), $source->statuses($shop)));
        $statuses = array_map(fn (array $row): HomeStatus => new HomeStatus(...$row), $rows);
        usort($statuses, fn (HomeStatus $a, HomeStatus $b): int => [$a->order, $a->key] <=> [$b->order, $b->key]);

        return $statuses;
    }

    /** @return list<ShopNotice> the weightiest first, then the newest, without those marked done */
    public function notices(Shop $shop): array
    {
        $done = $this->doneKeys($shop);

        return array_values(array_filter($this->raised($shop), fn (ShopNotice $n): bool => ! isset($done[$n->key])));
    }

    /** @return list<ShopNotice> what the modules still raise and the manager marked done */
    public function done(Shop $shop): array
    {
        $done = $this->doneKeys($shop);

        return array_values(array_filter($this->raised($shop), fn (ShopNotice $n): bool => isset($done[$n->key])));
    }

    /** The manager did it: the notice leaves the home and the list until brought back. */
    public function markDone(Shop $shop, string $key, ?int $userId = null): void
    {
        if ($key === '' || mb_strlen($key) > 191) {
            return;
        }

        DB::table('shop_notices_done')->insertOrIgnore(['shop_id' => $shop->id, 'key' => $key, 'user_id' => $userId, 'created_at' => now()]);
    }

    public function undo(Shop $shop, string $key): void
    {
        DB::table('shop_notices_done')->where('shop_id', $shop->id)->where('key', $key)->delete();
    }

    /** @return array<string, true> */
    private function doneKeys(Shop $shop): array
    {
        return array_fill_keys(DB::table('shop_notices_done')->where('shop_id', $shop->id)->pluck('key')->all(), true);
    }

    /** @return list<ShopNotice> every notice the modules raise, the weightiest first, then the newest */
    private function raised(Shop $shop): array
    {
        $rows = $this->remember($shop, 'notices', fn (HomeSource $source): array => array_map(
            fn (ShopNotice $n): array => ['at' => $n->at?->toIso8601String()] + get_object_vars($n),
            $source->notices($shop),
        ));
        $notices = array_map(fn (array $row): ShopNotice => new ShopNotice(...['at' => $row['at'] === null ? null : Carbon::parse($row['at'])] + $row), $rows);
        usort($notices, fn (ShopNotice $a, ShopNotice $b): int => [$b->weight, $b->at?->getTimestamp() ?? 0, $a->key] <=> [$a->weight, $a->at?->getTimestamp() ?? 0, $b->key]);

        return $notices;
    }

    /**
     * Today's cards: up to three things to do, to set up or to know, a different three each day
     * when there are more, so the same card does not greet the manager every morning.
     *
     * @return list<ShopNotice>
     */
    public function today(Shop $shop, int $count = 3): array
    {
        $cards = array_values(array_filter($this->notices($shop), fn (ShopNotice $n): bool => $n->kind !== ShopNotice::REPORT));

        if (count($cards) <= $count) {
            return $cards;
        }

        $start = (int) now()->dayOfYear % count($cards);

        return array_slice([...array_slice($cards, $start), ...array_slice($cards, 0, $start)], 0, $count);
    }

    /** @return list<ModuleFigure> */
    public function figures(Shop $shop, int $days): array
    {
        $until = now();
        $since = $until->copy()->subDays($days)->startOfDay();
        $before = $since->copy()->subDays($days);
        $figures = $this->ask($shop, fn (HomeSource $source): array => $source->figures($shop, $since, $until, $before));
        usort($figures, fn (ModuleFigure $a, ModuleFigure $b): int => [$a->order, $a->key] <=> [$b->order, $b->key]);

        return $figures;
    }

    /** @return array<string, mixed>|null */
    public function numbers(Shop $shop, int $days): ?array
    {
        if (! app()->bound(HomeNumbers::class)) {
            return null;
        }

        try {
            return $this->tenant->run((string) $shop->id, fn (): array => app(HomeNumbers::class)->numbers($shop, $days));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @param  callable(HomeSource): list<mixed>  $ask
     * @return list<mixed>
     */
    private function remember(Shop $shop, string $what, callable $ask): array
    {
        return Cache::remember("home:{$shop->id}:{$what}:".app()->getLocale(), self::CACHE_SECONDS, fn (): array => $this->ask($shop, $ask));
    }

    /**
     * @param  callable(HomeSource): list<mixed>  $ask
     * @return list<mixed>
     */
    private function ask(Shop $shop, callable $ask): array
    {
        $answers = [];

        foreach (app()->tagged(self::TAG) as $source) {
            try {
                array_push($answers, ...$this->tenant->run((string) $shop->id, fn (): array => $ask($source)));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $answers;
    }

    /** After the manager acts on something (answers a question, turns a part on), the home shows it at once. */
    public static function forget(Shop|string $shop): void
    {
        $id = $shop instanceof Shop ? (string) $shop->id : $shop;

        foreach (['statuses', 'notices'] as $what) {
            foreach (['he', 'en'] as $locale) {
                Cache::forget("home:{$id}:{$what}:{$locale}");
            }
        }
    }
}
