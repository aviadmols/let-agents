<?php

namespace App\Modules\Admin\Models;

use App\Core\Facades\Features;
use App\Core\Localization\Locales;
use App\Modules\Admin\Database\Factories\UserFactory;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Someone who signs in to an admin panel.
 *
 * Operators (is_operator) run the whole system and see every shop. Everyone else is a
 * merchant user and sees only the shops they are a member of.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_operator
 * @property string|null $locale admin UI language; null follows the browser
 * @property string|null $theme light, dark or system; null follows what this browser chose last
 * @property Carbon|null $last_login_at
 */
class User extends Authenticatable implements FilamentUser, HasTenants
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    public const OPERATOR_PANEL = 'operator';

    public const MERCHANT_PANEL = 'merchant';

    /** One rule for every place a password is set: the admin form and the operator command. */
    public const MIN_PASSWORD_LENGTH = 8;

    /** How the panel is drawn: as the device is set, or always light or dark. */
    public const THEMES = ['system', 'light', 'dark'];

    protected $table = 'users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'locale',
        'theme',
        'is_operator',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_operator' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    /** @return BelongsToMany<Shop, $this> */
    public function shops(): BelongsToMany
    {
        return $this->belongsToMany(Shop::class, 'shop_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    public function preferredLocale(): ?string
    {
        return Locales::isSupported($this->locale) ? $this->locale : null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return match ($panel->getId()) {
            self::OPERATOR_PANEL => $this->is_operator,
            self::MERCHANT_PANEL => $this->is_operator || $this->shops()->exists(),
            default => false,
        };
    }

    /** @return Collection<int, Shop> */
    public function getTenants(Panel $panel): Collection
    {
        /** @var EloquentCollection<int, Shop> $shops */
        $shops = $this->is_operator
            ? Shop::query()->orderBy('name')->get()
            : $this->shops()->orderBy('name')->get();

        return $shops->filter(fn (Shop $shop): bool => $this->canAccessTenant($shop))->values();
    }

    public function canAccessTenant(Model $tenant): bool
    {
        if (! $tenant instanceof Shop) {
            return false;
        }

        // Operators can open any shop's merchant view, to see exactly what the merchant sees.
        if ($this->is_operator) {
            return true;
        }

        return $tenant->status !== ShopStatus::Disabled
            && Features::enabled('admin.merchant_panel', $tenant->id)
            && $this->shops()->whereKey($tenant->getKey())->exists();
    }

    public function attachShop(Shop $shop, ShopRole $role = ShopRole::Member): void
    {
        $this->shops()->syncWithoutDetaching([$shop->getKey() => ['role' => $role->value]]);
    }

    /** This person's role in the shop; null when they are not one of its people (an operator looking in). */
    public function roleIn(Shop|string $shop): ?ShopRole
    {
        $role = $this->shops()->whereKey($shop instanceof Shop ? $shop->getKey() : $shop)->first()?->pivot?->role;

        return $role === null ? null : (ShopRole::tryFrom((string) $role) ?? ShopRole::Member);
    }

    /** May change the shop's settings and data: everyone but a viewer. An operator always may. */
    public function canEditShop(Shop|string $shop): bool
    {
        return $this->is_operator || ($this->roleIn($shop)?->canEdit() ?? false);
    }

    /** May invite and remove people and change the connection: an owner, or an operator. */
    public function managesShop(Shop|string $shop): bool
    {
        return $this->is_operator || ($this->roleIn($shop)?->managesAccount() ?? false);
    }

    public function preferredTheme(): ?string
    {
        return in_array($this->theme, self::THEMES, true) ? $this->theme : null;
    }
}
