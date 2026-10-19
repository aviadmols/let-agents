<?php

namespace App\Modules\Leads\Filament\Merchant\Pages;

use App\Core\Facades\Settings;
use App\Core\Filament\EditsShopSettings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Leads\Enums\LeadGoal;
use App\Modules\Leads\Models\LeadFlow;
use App\Modules\Leads\Support\LeadFields;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Settings · Leaving details, as the designer's panel lays it out: whether a shopper may leave a way back
 * when the assistant has no answer or the team is away (shoppers.callbacks), the fields asked, in order,
 * each required or not, from a bank or the shop's own (the flow in force, LeadFlow: a changed list is a
 * new version, so every lead stays readable against what was asked), the form's heading, what it
 * promises, and the row of products the shopper viewed.
 */
class LeaveDetails extends Page
{
    use EditsShopSettings;

    public static string $sideGroup = 'team';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserPlus;

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 80;

    protected static ?string $slug = 'settings/leave-details';

    protected string $view = 'leads::merchant.settings.leave-details';

    public static function getNavigationLabel(): string
    {
        return __('leads::shop_settings.title');
    }

    public function getTitle(): string
    {
        return __('admin::shop_settings.title');
    }

    public function getSubheading(): ?string
    {
        return __('admin::shop_settings.subheading');
    }

    protected function fields(): array
    {
        return [
            'on' => 'feature:shoppers.callbacks',
            'recent' => 'feature:shoppers.recent_products',
        ];
    }

    /**
     * The bank in the shop's language (what shoppers will read), for the screen's "+" chips.
     *
     * @return list<array{key: string, type: string, label: string}>
     */
    public function bank(): array
    {
        return LeadFields::bank($this->locale());
    }

    protected function loadMore(): void
    {
        $flow = $this->flow();
        $locale = $this->locale();

        $this->data['fields'] = array_map(fn (array $f): array => [
            'key' => (string) $f['key'], 'type' => (string) $f['type'], 'label' => (string) ($f['label'] ?? $f['key']), 'required' => (bool) ($f['required'] ?? false),
        ], $flow === null ? LeadFields::defaults($locale) : LeadFields::clean($flow->asked(), $this->known($flow), $locale));
        $this->data['title'] = LeadFields::title($this->shopId(), $locale);
        $this->data['promise'] = trim((string) $flow?->promise) ?: LeadFields::promise($locale);
    }

    protected function checkMore(): void
    {
        foreach (['title' => 120, 'promise' => 120] as $field => $max) {
            if (mb_strlen(trim((string) ($this->data[$field] ?? ''))) > $max) {
                $this->problems[$field] = __('leads::shop_settings.too_long', ['n' => $max]);
            }
        }

        $this->data['fields'] = is_array($this->data['fields'] ?? null) ? $this->data['fields'] : [];
    }

    protected function saveMore(): void
    {
        $shopId = $this->shopId();
        $locale = $this->locale();

        // The built-in heading stays the built-in one (and follows its wording); only the shop's own is kept.
        $title = trim((string) ($this->data['title'] ?? ''));
        if ($title === '' || $title === (string) __('leads::form.title', [], $locale)) {
            Settings::clear('leads.form_title', $shopId);
        } else {
            Settings::set('leads.form_title', $title, $shopId);
        }

        app(TenantContext::class)->run($shopId, function () use ($shopId, $locale, $title): void {
            $current = LeadFlow::inForce($shopId);
            $fields = LeadFields::clean((array) $this->data['fields'], $this->known($current), $locale);
            $promise = mb_substr(trim((string) ($this->data['promise'] ?? '')), 0, 120) ?: null;

            if ($current !== null && $current->asked() == $fields && trim((string) $current->promise) === (string) $promise) {
                return;
            }

            // A new version, never an edit: every lead stays readable against what was asked.
            $version = (int) (LeadFlow::query()->where('shop_id', $shopId)->max('version') ?? 0) + 1;
            LeadFlow::query()->where('shop_id', $shopId)->update(['active' => false]);
            LeadFlow::query()->create([
                'shop_id' => $shopId,
                'version' => $version,
                'goal' => $current?->goal ?? LeadGoal::Advice,
                'offer' => $current?->offer ?? mb_substr($title !== '' ? $title : (string) __('leads::form.title', [], $locale), 0, 200),
                'consent' => $current?->consent ?? (string) __('leads::flow.consent_default', [], $locale),
                'fields' => $fields,
                'promise' => $promise,
                'active' => true,
                'created_by' => Auth::id(),
            ]);
        });
    }

    protected function saved(): void
    {
        $this->loadMore();
    }

    private function flow(): ?LeadFlow
    {
        $shopId = $this->shopId();

        return $shopId === '' ? null : app(TenantContext::class)->run($shopId, fn (): ?LeadFlow => LeadFlow::inForce($shopId));
    }

    /** @return array<string, array<string, mixed>> the flow's fields by key: they keep their type */
    private function known(?LeadFlow $flow): array
    {
        $known = [];

        foreach ($flow?->asked() ?? [] as $field) {
            $known[(string) $field['key']] = $field;
        }

        return $known;
    }

    private function locale(): string
    {
        $shop = Filament::getTenant();

        return LeadFields::locale($shop instanceof Shop ? (string) $shop->content_locale : null);
    }
}
