<?php

namespace App\Modules\Shoppers\Filament\Operator\Pages;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Shoppers\Contracts\VisitHistory;
use App\Modules\Shoppers\Models\ShopperCallback;
use App\Modules\Shoppers\Models\ShopperIdentity;
use App\Modules\Shoppers\Support\Channels;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Everyone who left a phone or an email in one store, newest first, with the products they looked
 * at. The contact is decrypted only here, for the person who runs the store.
 */
class ShopSignUps extends Page
{
    private const PER_PAGE = 50;

    private const PRODUCTS_EACH = 6;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static string|UnitEnum|null $navigationGroup = 'shoppers';

    protected static ?int $navigationSort = 50;

    protected static ?string $slug = 'shoppers/signups';

    protected string $view = 'shoppers::operator.signups';

    #[Url]
    public ?string $shop = null;

    public static function getNavigationLabel(): string
    {
        return __('shoppers::ui.signups.title');
    }

    public function getTitle(): string
    {
        return __('shoppers::ui.signups.title');
    }

    public function getSubheading(): ?string
    {
        return __('shoppers::ui.signups.subheading');
    }

    public function mount(): void
    {
        // The shop the panel is inside, so every screen agrees; the first by name when it is
        // looking across every shop.
        $this->shop ??= app(TenantContext::class)->id() ?? Shop::query()->orderBy('name')->value('id');
    }

    /** False in the merchant panel, where the shop is the one in the address and cannot change. */
    public function picksShop(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function shops(): array
    {
        return Shop::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Forget one shopper: the contact, the consent and the browsers tied to it. What they browsed
     * stays as it was before they ever signed up — anonymous, per browser, and no longer theirs.
     * The wording they agreed to says they may ask for this.
     */
    public function forget(int $id): void
    {
        if ($this->shop === null) {
            return;
        }

        app(TenantContext::class)->run($this->shop, function () use ($id): void {
            ShopperIdentity::query()->whereKey($id)->delete();
        });

        Notification::make()->success()->title(__('shoppers::ui.signups.forgotten'))->send();
    }

    /** @return array{on: bool, can_verify: array<string, bool>, people: list<array<string, mixed>>}|null */
    /**
     * People waiting on an answer the assistant did not have, newest first. The question is in
     * the open because the team has to read it; the contact stays masked until they act on it.
     *
     * @return list<array<string, mixed>>
     */
    public function waiting(): array
    {
        if ($this->shop === null) {
            return [];
        }

        return app(TenantContext::class)->run($this->shop, fn (): array => ShopperCallback::query()
            ->with('identity:id,contact_masked,channel')
            ->whereNull('answered_at')
            ->latest('created_at')
            ->limit(30)
            ->get()
            ->map(fn (ShopperCallback $row): array => [
                'id' => $row->id,
                'question' => $row->question,
                'contact' => $row->identity?->contact_masked,
                'channel' => $row->identity?->channel,
                'page' => $row->page_id,
                'at' => $row->created_at,
            ])
            ->all());
    }

    /** The team came back to them; it stops being a thing waiting. */
    public function answered(int $id): void
    {
        if ($this->shop === null) {
            return;
        }

        app(TenantContext::class)->run($this->shop, fn () => ShopperCallback::query()->whereKey($id)->update(['answered_at' => now()]));
    }

    public function signUps(): ?array
    {
        if ($this->shop === null) {
            return null;
        }

        $shopId = $this->shop;
        $history = app(VisitHistory::class);

        return app(TenantContext::class)->run($shopId, function () use ($shopId, $history): array {
            $identities = ShopperIdentity::query()->withCount('visitors')->latest('id')->limit(self::PER_PAGE)->get();

            $viewed = [];
            foreach ($identities as $identity) {
                $viewed[$identity->id] = $history->topProductsForIdentity($shopId, $identity->id, self::PRODUCTS_EACH);
            }

            $ids = collect($viewed)->flatten(1)->pluck('id')->unique()->all();
            $titles = $ids === []
                ? collect()
                : CatalogProduct::query()->whereIn('external_id', $ids)->pluck('title', 'external_id');

            return [
                'on' => Features::enabled('shoppers.signup', $shopId),
                'can_verify' => [
                    'email' => Channels::canSendEmail(),
                    'phone' => Channels::canVerify('phone', $shopId),
                ],
                'people' => $identities->map(fn (ShopperIdentity $identity): array => [
                    'id' => $identity->id,
                    'contact' => $identity->contact,
                    'channel' => $identity->channel,
                    'verified' => $identity->isVerified(),
                    'devices' => (int) $identity->visitors_count,
                    'signed_up_at' => $identity->created_at,
                    'last_seen_at' => $identity->last_seen_at,
                    'consent_version' => $identity->consent_version,
                    'viewed' => array_map(fn (array $row): array => [
                        'id' => $row['id'],
                        'title' => $titles[$row['id']] ?? $row['id'],
                        'views' => $row['views'],
                    ], $viewed[$identity->id]),
                ])->all(),
            ];
        });
    }
}
