<?php

namespace App\Core\Facades;

use App\Core\Settings\SettingManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static int|float|bool|string get(string $key, ?string $shopId = null)
 * @method static int|float|bool|string getForCurrentShop(string $key)
 * @method static \App\Core\Settings\ValueSource source(string $key, ?string $shopId = null)
 * @method static int|float|bool|string|null overrideFor(string $key, ?string $shopId = null)
 * @method static int|float|bool|string set(string $key, mixed $value, ?string $shopId = null)
 * @method static int|float|bool|string normalize(string $key, mixed $value)
 * @method static void clear(string $key, ?string $shopId = null)
 * @method static void purgeShop(string $shopId)
 *
 * @see SettingManager
 */
final class Settings extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SettingManager::class;
    }
}
