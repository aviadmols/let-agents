<?php

namespace App\Core\Settings;

use App\Core\Settings\Models\SettingOverride;
use App\Core\Tenancy\TenantContext;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Log;

/**
 * Reads and writes the caps, thresholds and choices modules declare.
 *
 * Resolution order: the shop's override (only for shop-scoped settings), then the global
 * override, then the declared default. Every write is normalized against the declaration,
 * so a value outside its range can never be stored.
 */
final class SettingManager
{
    private const CACHE_KEY = 'core:setting_overrides';

    /** @var array<string, array<string, mixed>>|null key => scope => raw value */
    private ?array $overrides = null;

    public function __construct(
        private readonly SettingRegistry $registry,
        private readonly TenantContext $tenant,
        private readonly Cache $cache,
    ) {}

    public function get(string $key, ?string $shopId = null): int|float|bool|string
    {
        return $this->resolve($key, $shopId)[0];
    }

    public function getForCurrentShop(string $key): int|float|bool|string
    {
        return $this->get($key, $this->tenant->require());
    }

    public function source(string $key, ?string $shopId = null): ValueSource
    {
        return $this->resolve($key, $shopId)[1];
    }

    /** The stored override for exactly this scope, or null when it inherits. */
    public function overrideFor(string $key, ?string $shopId = null): int|float|bool|string|null
    {
        $definition = $this->registry->get($key);
        $raw = $this->overrides()[$key][OverrideScope::for($shopId)] ?? null;

        return $raw === null ? null : $this->valid($definition, $raw, OverrideScope::for($shopId));
    }

    /**
     * @throws InvalidSettingValue
     * @throws UnknownSetting
     */
    public function set(string $key, mixed $value, ?string $shopId = null): int|float|bool|string
    {
        $definition = $this->registry->get($key);

        if ($shopId !== null && ! $definition->isShopOverridable()) {
            throw UnknownSetting::notShopScoped($key);
        }

        $normalized = $definition->normalize($value);

        SettingOverride::query()->updateOrCreate(
            ['key' => $key, 'scope' => OverrideScope::for($shopId)],
            ['value' => $normalized],
        );

        $this->flush();

        return $normalized;
    }

    /**
     * The value as the setting keeps it, without keeping it: for checking a whole form before any of it is saved.
     *
     * @throws InvalidSettingValue
     * @throws UnknownSetting
     */
    public function normalize(string $key, mixed $value): int|float|bool|string
    {
        return $this->registry->get($key)->normalize($value);
    }

    public function clear(string $key, ?string $shopId = null): void
    {
        SettingOverride::query()
            ->where('key', $key)
            ->where('scope', OverrideScope::for($shopId))
            ->delete();

        $this->flush();
    }

    public function purgeShop(string $shopId): void
    {
        SettingOverride::query()->where('scope', OverrideScope::for($shopId))->delete();

        $this->flush();
    }

    /** @return array{0: int|float|bool|string, 1: ValueSource} */
    private function resolve(string $key, ?string $shopId): array
    {
        $definition = $this->registry->get($key);
        $overrides = $this->overrides()[$key] ?? [];

        if ($shopId !== null && $definition->isShopOverridable() && array_key_exists($shopId, $overrides)) {
            $value = $this->valid($definition, $overrides[$shopId], $shopId);

            if ($value !== null) {
                return [$value, ValueSource::Shop];
            }
        }

        if (array_key_exists(OverrideScope::GLOBAL, $overrides)) {
            $value = $this->valid($definition, $overrides[OverrideScope::GLOBAL], OverrideScope::GLOBAL);

            if ($value !== null) {
                return [$value, ValueSource::Global];
            }
        }

        return [$definition->default, ValueSource::Default];
    }

    /**
     * A stored value that no longer fits its declaration (a module tightened a range) is
     * ignored rather than trusted: the system falls back to the next level and says so in
     * the log, instead of running with a cap it would now refuse to save.
     */
    private function valid(SettingDefinition $definition, mixed $raw, string $scope): int|float|bool|string|null
    {
        try {
            return $definition->normalize($raw);
        } catch (InvalidSettingValue $e) {
            Log::warning('Ignoring stored setting override that no longer validates.', [
                'key' => $definition->key(),
                'scope' => $scope,
                'reason' => $e->reason,
            ]);

            return null;
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function overrides(): array
    {
        return $this->overrides ??= $this->cache->rememberForever(self::CACHE_KEY, function (): array {
            $map = [];

            foreach (SettingOverride::query()->get(['key', 'scope', 'value']) as $row) {
                $map[$row->key][$row->scope] = $row->value;
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
