<?php

namespace App\Modules\Admin\Support;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\FirstStep;
use App\Modules\Admin\Contracts\FirstSteps;
use App\Modules\Admin\Contracts\HomeCard;
use App\Modules\Admin\Contracts\HomeCards;
use App\Modules\Admin\Contracts\HomeNumbers;
use App\Modules\Admin\Contracts\HomeSource;
use App\Modules\Admin\Contracts\HomeStatus;
use App\Modules\Admin\Contracts\ModuleFigure;
use App\Modules\Admin\Contracts\ShopNotice;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;
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
    public function numbers(Shop $shop, int $days, ?CarbonInterface $since = null, ?CarbonInterface $until = null): ?array
    {
        if (! app()->bound(HomeNumbers::class)) {
            return null;
        }

        try {
            return $this->tenant->run((string) $shop->id, fn (): array => app(HomeNumbers::class)->numbers($shop, $days, $since, $until));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The cards at the top of the home (HomeCards, 'admin.home_cards'), by order, for the period and
     * the one of the same length before it.
     *
     * @return list<HomeCard>
     */
    public function cards(Shop $shop, CarbonInterface $since, CarbonInterface $until): array
    {
        $before = $since->copy()->sub($since->diff($until));
        $cards = $this->askTagged(HomeCards::CARDS_TAG, $shop, fn (HomeCards $source): array => $source->cards($shop, $since, $until, $before));
        usort($cards, fn (HomeCard $a, HomeCard $b): int => [$a->order, $a->key] <=> [$b->order, $b->key]);

        return $cards;
    }

    /**
     * The shop's first steps (FirstSteps, 'admin.first_steps'), by order. The page shows them until all
     * are done and the manager hides the closing line.
     *
     * @return list<FirstStep>
     */
    public function steps(Shop $shop): array
    {
        $steps = $this->askTagged(FirstSteps::STEPS_TAG, $shop, fn (FirstSteps $source): array => $source->steps($shop));
        usort($steps, fn (FirstStep $a, FirstStep $b): int => [$a->order, $a->key] <=> [$b->order, $b->key]);

        return $steps;
    }

    /**
     * What waits for the manager, for the home's "needs your attention": the weightiest few things to do
     * or set up, then an insight.
     *
     * @return list<ShopNotice>
     */
    public function attention(Shop $shop, int $count = 4): array
    {
        $notices = $this->notices($shop);
        $todo = array_values(array_filter($notices, fn (ShopNotice $n): bool => $n->kind === ShopNotice::TODO || $n->kind === ShopNotice::SETUP));
        $insights = array_values(array_filter($notices, fn (ShopNotice $n): bool => $n->kind === ShopNotice::INSIGHT));

        return array_slice([...array_slice($todo, 0, $count - (count($insights) > 0 ? 1 : 0)), ...$insights], 0, $count);
    }

    /**
     * @template T
     *
     * @param  callable(object): list<T>  $ask
     * @return list<T>
     */
    private function askTagged(string $tag, Shop $shop, callable $ask): array
    {
        $answers = [];

        foreach (app()->tagged($tag) as $source) {
            try {
                array_push($answers, ...$this->tenant->run((string) $shop->id, fn (): array => $ask($source)));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $answers;
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
