<?php

namespace App\Modules\Tenancy\Models;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Localization\Locales;
use App\Core\Usage\MonthlyUsage;
use App\Modules\Tenancy\Database\Factories\ShopFactory;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Enums\Vertical;
use App\Modules\Tenancy\Support\ShopPlanCeilings;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A store connected to the system. The root every other shop-owned record points at.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property ShopPlatform $platform
 * @property string $domain
 * @property string $content_locale
 * @property string $currency
 * @property string $timezone
 * @property ShopStatus $status
 * @property ?Vertical $vertical
 * @property int $vertical_confidence
 * @property bool $vertical_locked
 * @property array{kind?: string, languages?: list<string>, sells?: list<string>}|null $profile what the shop said about itself: a store, a content site or both; its other languages; what it sells
 * @property Carbon|null $trial_ends_at while set the shop is on trial (StartTrial), and stays limited past the date until the trial is ended
 * @property array<string, array{0: string, 1: mixed}>|null $trial_saved the shop's own settings from before the trial
 * @property ?string $plan the code of the plan the shop pays for (Plan); null: no plan holds it
 * @property Carbon|null $cancelled_at the owner ended the plan: the shop is paused, its data kept
 * @property Carbon|null $deletion_due_at the owner asked for the shop to be deleted: paused until then, deleted on this day unless kept
 * @property string|null $deletion_asked_by the email of who asked for it
 * @property Carbon|null $onboarded_at when the manager went through the first-run screens; until then the panel opens on them
 */
class Shop extends Model
{
    /** Names a shop's address may not take: the platform's own addresses. */
    public const RESERVED_SLUGS = ['www', 'app', 'api', 'admin', 'operator', 'merchant', 'mail', 'static', 'assets', 'cdn', 'status', 'help', 'docs', 'agents'];

    /** @use HasFactory<ShopFactory> */
    use HasFactory;

    use HasUlids;

    protected $fillable = [
        'name',
        'profile',
        'slug',
        'platform',
        'domain',
        'content_locale',
        'currency',
        'timezone',
        'status',
        'vertical',
        'vertical_locked',
        'plan',
    ];

    protected $attributes = [
        'content_locale' => 'he',
        'currency' => 'ILS',
        'timezone' => 'Asia/Jerusalem',
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'platform' => ShopPlatform::class,
            'status' => ShopStatus::class,
            'vertical' => Vertical::class,
            'vertical_locked' => 'boolean',
            'profile' => 'array',
            'trial_ends_at' => 'datetime',
            'trial_saved' => 'array',
            'cancelled_at' => 'datetime',
            'deletion_due_at' => 'datetime',
            'onboarded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // a shop on another plan is under other ceilings from its next request on
        static::saved(function (Shop $shop): void {
            if ($shop->wasChanged('plan') || $shop->wasRecentlyCreated && $shop->plan !== null) {
                ShopPlanCeilings::flush();
            }
        });

        // The kernel's tables have no foreign key to shops, so clean them here.
        static::deleted(function (Shop $shop): void {
            Features::purgeShop($shop->id);
            Settings::purgeShop($shop->id);
            MonthlyUsage::forgetShop($shop->id);
        });
    }

    protected static function newFactory(): ShopFactory
    {
        return ShopFactory::new();
    }

    /** @return HasMany<ShopApiKey, $this> */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ShopApiKey::class);
    }

    /** The plan the shop pays for, or null when no plan holds it. */
    public function currentPlan(): ?Plan
    {
        return Plan::byCode($this->plan);
    }

    /** On trial: limited by TrialLimits until the trial is ended, also after its last day. */
    public function onTrial(): bool
    {
        return $this->trial_ends_at !== null;
    }

    /** Days left of the trial; 0 once its last day has passed. */
    public function trialDaysLeft(): int
    {
        return $this->trial_ends_at === null ? 0 : max(0, (int) ceil(now()->diffInHours($this->trial_ends_at, false) / 24));
    }

    public function isActive(): bool
    {
        return $this->status === ShopStatus::Active;
    }

    /** The owner asked for the shop to be deleted, and it is not yet. */
    public function leaving(): bool
    {
        return $this->deletion_due_at !== null;
    }

    /** "rtl" or "ltr" for the language visitors see. */
    public function contentDirection(): string
    {
        return Locales::direction($this->content_locale);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
