<?php

namespace App\Core\Features;

use App\Core\Features\Models\FeatureOverride;
use App\Core\Plans\NoCeilings;
use App\Core\Plans\PlanCeilings;
use App\Core\Settings\OverrideScope;
use App\Core\Settings\ValueSource;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Answers "is this feature on?" for the system or for one shop.
 *
 * Resolution order: the shop's override, then the global override, then the default the
 * module declared. Asking about a feature no enabled module declared is a bug, so it throws.
 * A shop's plan may hold a feature off above all of these (PlanCeilings), never on.
 */
final class FeatureManager
{
    private const CACHE_KEY = 'core:feature_overrides';

    /** @var array<string, array<string, bool>>|null key => scope => enabled */
    private ?array $overrides = null;

    public function __construct(
        private readonly FeatureRegistry $registry,
        private readonly TenantContext $tenant,
        private readonly Cache $cache,
        private readonly PlanCeilings $plans = new NoCeilings,
    ) {}

    public function enabled(string $key, ?string $shopId = null): bool
    {
        return $this->resolve($key, $shopId)[0];
    }

    public function enabledForCurrentShop(string $key): bool
    {
        return $this->enabled($key, $this->tenant->require());
    }

    public function source(string $key, ?string $shopId = null): ValueSource
    {
        return $this->resolve($key, $shopId)[1];
    }

    /** The stored override for exactly this scope, or null when it inherits. */
    public function overrideFor(string $key, ?string $shopId = null): ?bool
    {
        $this->registry->get($key);

        return $this->overrides()[$key][OverrideScope::for($shopId)] ?? null;
    }

    public function override(string $key, bool $enabled, ?string $shopId = null): void
    {
        $this->registry->get($key);

        FeatureOverride::query()->updateOrCreate(
            ['key' => $key, 'scope' => OverrideScope::for($shopId)],
            ['enabled' => $enabled],
        );

        $this->flush();
    }

    public function clearOverride(string $key, ?string $shopId = null): void
    {
        FeatureOverride::query()
            ->where('key', $key)
            ->where('scope', OverrideScope::for($shopId))
            ->delete();

        $this->flush();
    }

    public function purgeShop(string $shopId): void
    {
        FeatureOverride::query()->where('scope', OverrideScope::for($shopId))->delete();

        $this->flush();
    }

    /** @return array{0: bool, 1: ValueSource} */
    private function resolve(string $key, ?string $shopId): array
    {
        $found = $this->stored($key, $shopId);

        if ($found[0] && $shopId !== null && ($this->plans->for($shopId)['features'][$key] ?? null) === false) {
            return [false, ValueSource::Plan];
        }

        return $found;
    }

    /** @return array{0: bool, 1: ValueSource} */
    private function stored(string $key, ?string $shopId): array
    {
        $definition = $this->registry->get($key);
        $overrides = $this->overrides()[$key] ?? [];

        if ($shopId !== null && isset($overrides[$shopId])) {
            return [$overrides[$shopId], ValueSource::Shop];
        }

        if (isset($overrides[OverrideScope::GLOBAL])) {
            return [$overrides[OverrideScope::GLOBAL], ValueSource::Global];
        }

        return [$definition->default, ValueSource::Default];
    }

    /** @return array<string, array<string, bool>> */
    private function overrides(): array
    {
        return $this->overrides ??= $this->cache->rememberForever(self::CACHE_KEY, function (): array {
            $map = [];

            foreach (FeatureOverride::query()->get(['key', 'scope', 'enabled']) as $row) {
                $map[$row->key][$row->scope] = $row->enabled;
            }

            return $map;
        });
    }

    private function flush(): void
    {
        $this->overrides = null;
        $this->cache->forget(self::CACHE_KEY);
    }
}
