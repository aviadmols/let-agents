<?php

namespace App\Modules\Tenancy\Support;

use App\Core\Features\FeatureRegistry;
use App\Core\Plans\PlanCeilings;
use App\Core\Settings\SettingRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Each shop's ceilings from the plan it is on (shops.plan → plans.limits). The kernel reads them
 * with every feature and setting of a shop, so they are kept in the cache as one small map, which
 * a plan's save and a shop's change of plan forget. A key no enabled module declares is left out.
 * Before the plans table exists (a deploy midway), there are none.
 */
final class ShopPlanCeilings implements PlanCeilings
{
    private const CACHE_KEY = 'tenancy:plan_ceilings';

    private const NONE = ['features' => [], 'settings' => []];

    /** @var array{shops: array<string, string>, plans: array<string, array{features: array<string, bool>, settings: array<string, int|float|string|bool>}>}|null */
    private ?array $map = null;

    /** Raised by flush(): an instance that loaded before it loads again (a change of plan in this same request or job). */
    private static int $version = 0;

    private int $loaded = -1;

    public function __construct(
        private readonly FeatureRegistry $features,
        private readonly SettingRegistry $settings,
    ) {}

    public function for(string $shopId): array
    {
        if ($this->map === null || $this->loaded !== self::$version) {
            $this->loaded = self::$version;
            $this->map = $this->load();
        }

        $map = $this->map;
        $plan = $map['shops'][$shopId] ?? null;

        return $plan === null ? self::NONE : ($map['plans'][$plan] ?? self::NONE);
    }

    public static function flush(): void
    {
        self::$version++;
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array{shops: array<string, string>, plans: array<string, array{features: array<string, bool>, settings: array<string, int|float|string|bool>}>} */
    private function load(): array
    {
        try {
            /** @var array{shops: array<string, string>, plans: array<string, array<string, mixed>>} $map */
            $map = Cache::rememberForever(self::CACHE_KEY, fn (): array => [
                'shops' => DB::table('shops')->whereNotNull('plan')->pluck('plan', 'id')->all(),
                'plans' => DB::table('plans')->pluck('limits', 'code')->map(fn ($limits): array => (array) json_decode((string) $limits, true))->all(),
            ]);
        } catch (Throwable) {
            return ['shops' => [], 'plans' => []];
        }

        $plans = [];

        foreach ($map['plans'] as $code => $limits) {
            $plans[$code] = [
                'features' => array_filter((array) ($limits['features'] ?? []), fn (mixed $v, string $key): bool => $this->features->has($key), ARRAY_FILTER_USE_BOTH),
                'settings' => array_filter((array) ($limits['settings'] ?? []), fn (mixed $v, string $key): bool => $this->settings->has($key), ARRAY_FILTER_USE_BOTH),
            ];
        }

        return ['shops' => $map['shops'], 'plans' => $plans];
    }
}
