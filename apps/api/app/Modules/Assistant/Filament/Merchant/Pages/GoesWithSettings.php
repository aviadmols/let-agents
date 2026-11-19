<?php

namespace App\Modules\Assistant\Filament\Merchant\Pages;

use App\Core\Facades\Settings;
use App\Core\Filament\EditsShopSettings;
use App\Core\Settings\SettingRegistry;
use App\Core\Tenancy\TenantContext;
use App\Modules\Assistant\Models\AssistantGoesWithRules;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Settings · Products that go together: whether the assistant offers a product to go with the one a shopper asks about
 * (assistant.goes_with), which sources it may use (the store's own pairings, the goes-with map, a match by look), how many
 * it shows, which of the store's kinds may be paired with which, and what is never offered (AssistantGoesWithRules). A
 * merchant's screen: what changes for shoppers, no models and no costs.
 */
class GoesWithSettings extends Page
{
    use EditsShopSettings;

    public static string $sideGroup = 'shoppers';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'settings';

    protected static ?int $navigationSort = 51;

    protected static ?string $slug = 'settings/goes-with';

    protected string $view = 'assistant::merchant.settings.goes-with';

    /** @var array{types: array<string, string>, category: array<string, string>, vendor: array<string, string>} what a rule may name, by key with its label */
    public array $choices = ['types' => [], 'category' => [], 'vendor' => []];

    public static function getNavigationLabel(): string
    {
        return __('assistant::goes_with.title');
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
            'on' => 'feature:assistant.goes_with',
            'use_relations' => 'setting:assistant.goes_with_use_relations',
            'use_map' => 'setting:assistant.goes_with_use_map',
            'use_look' => 'setting:assistant.goes_with_use_look',
            'products' => 'setting:assistant.goes_with_products',
            'max_picks' => 'setting:assistant.goes_with_max_picks',
        ];
    }

    protected function loadMore(): void
    {
        $shop = $this->shopId();
        if ($shop === '') {
            return;
        }
        app(TenantContext::class)->run($shop, function () use ($shop): void {
            $rules = AssistantGoesWithRules::of($shop);
            $this->data['pairs'] = array_map(fn (array $p): array => ['a' => (string) $p['a'], 'b' => (string) $p['b'], 'allow' => (bool) ($p['allow'] ?? true)], $rules['pairs']);
            $this->data['never'] = array_map(fn (array $n): array => ['scope' => (string) $n['scope'], 'value' => (string) $n['value']], $rules['never']);
            $this->choices = $this->readChoices();
        });
    }

    /** The store's kinds (its vocabulary's types in the panel's language), categories and vendors, for the rules to name. */
    private function readChoices(): array
    {
        $types = [];
        foreach (EnrichmentVocabulary::query()->where('active', true)->orderBy('key')->get() as $vocabulary) {
            $definition = $vocabulary->definition();
            foreach ($definition->productTypes() as $type) {
                $types[(string) $type['key']] ??= (string) ($definition->label('type', (string) $type['key'], app()->getLocale()) ?: $type['key']);
            }
        }
        asort($types);
        $categories = CatalogCategory::query()->whereNull('removed_at')->orderBy('name')->pluck('name')->map(fn ($n): string => (string) $n)->unique()->all();
        $vendors = CatalogProduct::query()->whereNull('removed_at')->whereNotNull('brand')->where('brand', '!=', '')->distinct()->orderBy('brand')->pluck('brand')->map(fn ($b): string => (string) $b)->all();

        return ['types' => $types, 'category' => array_combine($categories, $categories) ?: [], 'vendor' => array_combine($vendors, $vendors) ?: []];
    }

    public function addPair(): void
    {
        $this->data['pairs'][] = ['a' => '', 'b' => '', 'allow' => true];
    }

    public function removePair(int $at): void
    {
        unset($this->data['pairs'][$at]);
        $this->data['pairs'] = array_values($this->data['pairs']);
    }

    public function addNever(): void
    {
        $this->data['never'][] = ['scope' => 'type', 'value' => ''];
    }

    public function removeNever(int $at): void
    {
        unset($this->data['never'][$at]);
        $this->data['never'] = array_values($this->data['never']);
    }

    /** A rule names kinds the store has, two different ones; a never-offer names a scope and a value; at most the cap of each. */
    protected function checkMore(): void
    {
        $max = (int) Settings::get('assistant.goes_with_rules_max');
        $pairs = [];
        foreach ((array) ($this->data['pairs'] ?? []) as $pair) {
            $a = trim((string) ($pair['a'] ?? ''));
            $b = trim((string) ($pair['b'] ?? ''));
            if ($a === '' && $b === '') {
                continue;
            }
            if ($a === '' || $b === '' || $a === $b || ! isset($this->choices['types'][$a]) || ! isset($this->choices['types'][$b])) {
                $this->problems['pairs'] = __('assistant::goes_with.pairs_problem');

                continue;
            }
            $pairs[] = ['a' => $a, 'b' => $b, 'allow' => (bool) ($pair['allow'] ?? true)];
        }
        $never = [];
        foreach ((array) ($this->data['never'] ?? []) as $rule) {
            $scope = (string) ($rule['scope'] ?? '');
            $value = trim((string) ($rule['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            if (! in_array($scope, AssistantGoesWithRules::SCOPES, true) || mb_strlen($value) > 120) {
                $this->problems['never'] = __('assistant::goes_with.never_problem');

                continue;
            }
            $never[] = ['scope' => $scope, 'value' => $value];
        }
        if (count($pairs) > $max || count($never) > $max) {
            $this->problems['rules'] = __('assistant::goes_with.rules_max', ['max' => $max]);
        }
        $this->data['pairs'] = $pairs;
        $this->data['never'] = $never;
        if (! in_array($this->data['max_picks'] ?? null, [1, 2, 3, '1', '2', '3'], true)) {
            $this->data['max_picks'] = 3;
        }
    }

    protected function saveMore(): void
    {
        $shop = $this->shopId();
        if ($shop === '') {
            return;
        }
        app(TenantContext::class)->run($shop, fn () => AssistantGoesWithRules::query()->updateOrCreate(['shop_id' => $shop], [
            'pairs' => array_values($this->data['pairs'] ?? []),
            'never' => array_values($this->data['never'] ?? []),
        ]));
    }

    /** @return array{min: int, max: int} a limit's bounds, as its module declares them */
    public function bounds(string $key): array
    {
        $definition = app(SettingRegistry::class)->get($key);

        return ['min' => (int) ($definition->min ?? 0), 'max' => (int) ($definition->max ?? 9999)];
    }
}
