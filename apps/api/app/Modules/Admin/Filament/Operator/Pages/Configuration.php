<?php

namespace App\Modules\Admin\Filament\Operator\Pages;

use App\Core\Features\FeatureDefinition;
use App\Core\Features\FeatureManager;
use App\Core\Features\FeatureRegistry;
use App\Core\Modules\ModuleManifest;
use App\Core\Modules\ModuleRepository;
use App\Core\Settings\InvalidSettingValue;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingManager;
use App\Core\Settings\SettingRegistry;
use App\Core\Settings\SettingType;
use App\Core\Tenancy\TenantContext;
use App\Modules\Tenancy\Models\Shop;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Every feature flag and setting every enabled module declared, one area at a time.
 *
 * All of it on one page was sixty-odd fields of scrolling. It is now a list of areas on the left
 * and one area's fields on the right, the way a shop's own settings are usually arranged: what
 * running a store is about first, then the platform's own tuning, one module per area. Only the
 * area on the screen is filled and only it is saved, so nothing off-screen can be cleared by a
 * save it was never part of.
 *
 * The shop is the one the panel is inside, never a separate choice made here, and it is read back
 * from the panel on every update so a crafted request cannot point a save somewhere else. Across
 * every shop, this screen edits the defaults every shop inherits.
 *
 * Nothing says "inherits". A switch is on or off where it stands, and a field whose box is empty
 * shows the value it would use as its placeholder. Leaving a switch where it already was, or a
 * box empty, is how a value stays the platform's rather than this shop's.
 */
class Configuration extends Page implements HasForms
{
    use InteractsWithForms;

    /**
     * What a shop's own settings are, in the order a shop owner thinks about them. The same list
     * the merchant's screen is built from, so the two screens never drift apart.
     *
     * @var array<string, list<string>>
     */
    public const SHOP_GROUPS = [
        'shown' => [
            'widget.on_products',
            'widget.on_content',
            'widget.layout',
            'widget.max_products',
            'widget.popularity',
            'widget.promises',
        ],
        'placement' => [
            'widget.product_selector',
            'widget.product_position',
            'widget.content_selector',
            'widget.content_position',
            'widget.floating_fallback',
        ],
        'panels' => [
            'widget.show_highlights',
            'widget.show_position',
            'widget.show_specs',
            'widget.show_complement',
            'widget.show_family',
            'widget.show_alternatives',
            'widget.show_on_sale',
            'widget.show_good_for',
            'widget.show_guides',
            'widget.show_article_products',
        ],
        'assistant' => [
            'assistant.on_products',
            'assistant.on_content',
        ],
        'search' => [
            'search.results',
            'search.drawer_side',
            'search.similar',
            'assistant.on_search',
            'assistant.search_whatsapp',
            'assistant.search_whatsapp_message',
            'assistant.ask_review',
        ],
        'whatsapp' => [
            'widget.whatsapp',
            'widget.whatsapp_number',
            'widget.whatsapp_title',
            'widget.whatsapp_button',
            'widget.whatsapp_message',
            'widget.whatsapp_offline_note',
            'widget.whatsapp_when_offline',
        ],
        'signup' => [
            'shoppers.recent_products',
            'shoppers.signup',
            'shoppers.signup_title',
            'shoppers.signup_consent',
            'shoppers.signup_note',
        ],
    ];

    /** An area built from a module rather than from the groups above. */
    private const MODULE_AREA = 'module:';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'system';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'configuration';

    protected string $view = 'admin::operator.configuration';

    /**
     * The shop whose values are on the screen, or null for the defaults every shop inherits.
     * Taken from the panel, never from the request.
     */
    public ?string $shop = null;

    /** Which area is open. */
    #[Url]
    public string $area = '';

    /** Bumped on every save, so the preview frame reloads with what was just saved. */
    public int $previewVersion = 0;

    /** @var array<string, mixed> */
    public array $data = [];

    public static function getNavigationLabel(): string
    {
        return __('admin::configuration.title');
    }

    public function getTitle(): string
    {
        return __('admin::configuration.title');
    }

    public function getSubheading(): ?string
    {
        $shop = $this->selectedShop();

        return $shop === null
            ? __('admin::configuration.scope_global_help')
            : __('admin::configuration.scope_shop_help', ['shop' => $shop->name]);
    }

    public function mount(): void
    {
        $this->takeShopFromPanel();
        $this->openArea($this->area);
    }

    /** Livewire hydrates public properties from the request, so the shop is taken again. */
    public function hydrate(): void
    {
        $this->takeShopFromPanel();
    }

    /** And again if anything assigns it mid-request, which is the door hydrating alone leaves open. */
    public function updatedShop(): void
    {
        $this->takeShopFromPanel();
    }

    protected function takeShopFromPanel(): void
    {
        $this->shop = app(TenantContext::class)->id();
    }

    /** Moves to an area and fills the form with that area's values only. */
    public function openArea(string $area): void
    {
        $areas = $this->areas();
        $this->area = isset($areas[$area]) ? $area : (string) array_key_first($areas);

        $state = [];

        foreach ($this->featuresHere() as $definition) {
            $override = $this->featureManager()->overrideFor($definition->key(), $this->shop);
            $state[self::field('f', $definition->key())] = $override ?? $this->inheritedFeature($definition);
        }

        foreach ($this->settingsHere() as $definition) {
            $override = $this->settingManager()->overrideFor($definition->key(), $this->shop);
            $state[self::field('s', $definition->key())] = match (true) {
                $definition->type === SettingType::Bool => $override ?? (bool) $this->inheritedSetting($definition),
                $override === null => null,
                default => (string) $override,
            };
        }

        $this->form->fill($state);
    }

    /**
     * The areas, in the order they are offered: what a store is, then one per module for the rest.
     *
     * @return array<string, array{title: string, help: string|null, keys: list<string>}>
     */
    public function areas(): array
    {
        $features = collect($this->features())->keyBy(fn (FeatureDefinition $d): string => $d->key());
        $settings = collect($this->settings())->keyBy(fn (SettingDefinition $d): string => $d->key());
        $known = fn (string $key): bool => $features->has($key) || $settings->has($key);

        $areas = [];
        $taken = [];

        foreach (self::SHOP_GROUPS as $group => $keys) {
            $keys = array_values(array_filter($keys, $known));

            if ($keys !== []) {
                $areas[$group] = [
                    'title' => __("admin::configuration.groups.{$group}.title"),
                    'help' => __("admin::configuration.groups.{$group}.help"),
                    'keys' => $keys,
                ];
                $taken = [...$taken, ...$keys];
            }
        }

        foreach (app(ModuleRepository::class)->enabled() as $module) {
            $keys = array_values(array_diff(
                [
                    ...array_map(fn (FeatureDefinition $d): string => $d->key(), $this->features($module)),
                    ...array_map(fn (SettingDefinition $d): string => $d->key(), $this->settings($module)),
                ],
                $taken,
            ));

            if ($keys !== []) {
                $areas[self::MODULE_AREA.$module->slug] = [
                    'title' => __($module->nameKey()),
                    'help' => null,
                    'keys' => $keys,
                ];
            }
        }

        return $areas;
    }

    /** Which areas belong to the store, so the list can put a line between them and the rest. */
    public function storeAreas(): array
    {
        return array_keys(array_intersect_key($this->areas(), self::SHOP_GROUPS));
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->columns(2)->components($this->fields());
    }

    /** @return list<Component> */
    protected function fields(): array
    {
        return [
            ...array_map(fn (FeatureDefinition $d) => $this->featureField($d), $this->featuresHere()),
            ...array_map(fn (SettingDefinition $d) => $this->settingField($d), $this->settingsHere()),
        ];
    }

    public function save(): void
    {
        $features = [];
        $settings = [];
        $errors = [];

        foreach ($this->featuresHere() as $definition) {
            $features[$definition->key()] = (bool) ($this->data[self::field('f', $definition->key())] ?? false);
        }

        // Validate everything first, so a single bad value saves nothing.
        foreach ($this->settingsHere() as $definition) {
            $field = self::field('s', $definition->key());
            $raw = $this->data[$field] ?? null;

            if ($definition->type === SettingType::Bool) {
                $settings[$definition->key()] = (bool) $raw;

                continue;
            }

            if ($raw === null || $raw === '') {
                $settings[$definition->key()] = null;

                continue;
            }

            try {
                $settings[$definition->key()] = $definition->normalize($raw);
            } catch (InvalidSettingValue $e) {
                $errors["data.{$field}"] = $e->translated();
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($features, $settings): void {
            // A value left where it already stood is not this shop's decision, so no override is
            // written; that is what used to be spelled out as "inherits".
            foreach ($this->featuresHere() as $definition) {
                $chosen = $features[$definition->key()];

                $chosen === $this->inheritedFeature($definition)
                    ? $this->featureManager()->clearOverride($definition->key(), $this->shop)
                    : $this->featureManager()->override($definition->key(), $chosen, $this->shop);
            }

            foreach ($this->settingsHere() as $definition) {
                $chosen = $settings[$definition->key()];
                $inherited = $this->inheritedSetting($definition);

                $chosen === null || ($definition->type === SettingType::Bool && $chosen === (bool) $inherited)
                    ? $this->settingManager()->clear($definition->key(), $this->shop)
                    : $this->settingManager()->set($definition->key(), $chosen, $this->shop);
            }
        });

        $this->previewVersion++;
        Notification::make()->success()->title(__('admin::configuration.saved'))->send();

        $this->openArea($this->area);
    }

    /** @return list<FeatureDefinition> */
    private function featuresHere(): array
    {
        $keys = $this->areas()[$this->area]['keys'] ?? [];

        return array_values(array_filter($this->features(), fn (FeatureDefinition $d): bool => in_array($d->key(), $keys, true)));
    }

    /** @return list<SettingDefinition> */
    private function settingsHere(): array
    {
        $keys = $this->areas()[$this->area]['keys'] ?? [];

        return array_values(array_filter($this->settings(), fn (SettingDefinition $d): bool => in_array($d->key(), $keys, true)));
    }

    /** What this flag would be without an override here: the platform's answer, or the default. */
    private function inheritedFeature(FeatureDefinition $definition): bool
    {
        return $this->shop === null ? $definition->default : $this->featureManager()->enabled($definition->key());
    }

    private function inheritedSetting(SettingDefinition $definition): mixed
    {
        return $this->shop === null ? $definition->default : $this->settingManager()->get($definition->key());
    }

    protected function featureField(FeatureDefinition $definition): Component
    {
        return Toggle::make(self::field('f', $definition->key()))
            ->label(__($definition->labelKey()))
            ->helperText(Lang::has($definition->descriptionKey()) ? __($definition->descriptionKey()) : null)
            ->inline(false);
    }

    protected function settingField(SettingDefinition $definition): Component
    {
        $name = self::field('s', $definition->key());
        $inherited = $this->inheritedSetting($definition);
        $help = array_filter([
            Lang::has($definition->descriptionKey()) ? __($definition->descriptionKey()) : null,
            $this->rangeHint($definition),
        ]);

        // An empty box is not empty in effect: it shows the value it would use.
        $field = match ($definition->type) {
            SettingType::Bool => Toggle::make($name)->inline(false),
            SettingType::Enum => Select::make($name)
                ->options(array_combine($definition->options, array_map(fn (string $o) => $this->optionLabel($definition, $o), $definition->options)))
                // The empty choice is the default, named as such, so it never reads like a second copy of an option.
                ->placeholder(__('admin::configuration.default_option', ['value' => $this->optionLabel($definition, (string) $inherited)])),
            SettingType::Int, SettingType::Float => TextInput::make($name)
                ->numeric()
                ->inputMode($definition->type === SettingType::Int ? 'numeric' : 'decimal')
                ->placeholder((string) $inherited)
                ->suffix($definition->unit !== null && Lang::has("core::settings.units.{$definition->unit}") ? __("core::settings.units.{$definition->unit}") : null),
            SettingType::String => TextInput::make($name)
                ->placeholder($inherited === '' ? __('admin::configuration.empty') : (string) $inherited),
        };

        return $field
            ->label(__($definition->labelKey()))
            ->helperText($help === [] ? null : implode(' ', $help));
    }

    private function rangeHint(SettingDefinition $definition): ?string
    {
        if (! in_array($definition->type, [SettingType::Int, SettingType::Float], true)) {
            return null;
        }

        if ($definition->min !== null && $definition->max !== null) {
            return __('admin::configuration.range', ['min' => $definition->min, 'max' => $definition->max]);
        }

        return null;
    }

    private function optionLabel(SettingDefinition $definition, string $option): string
    {
        $key = "{$definition->module}::settings.{$definition->name}.options.{$option}";

        return Lang::has($key) ? __($key) : $option;
    }

    /** @return list<FeatureDefinition> */
    protected function features(?ModuleManifest $module = null): array
    {
        $registry = app(FeatureRegistry::class);

        return array_values($module === null ? $registry->all() : $registry->forModule($module->slug));
    }

    /**
     * Global-only settings are hidden while a shop is selected: a shop cannot override them.
     *
     * @return list<SettingDefinition>
     */
    protected function settings(?ModuleManifest $module = null): array
    {
        $registry = app(SettingRegistry::class);
        $definitions = $module === null ? $registry->all() : $registry->forModule($module->slug);

        return array_values(array_filter(
            $definitions,
            fn (SettingDefinition $d): bool => $this->shop === null || $d->isShopOverridable(),
        ));
    }

    private function selectedShop(): ?Shop
    {
        return $this->shop === null ? null : Shop::query()->find($this->shop);
    }

    /** Dots would nest the form state, so keys become flat field names. */
    private static function field(string $kind, string $key): string
    {
        return $kind.'__'.str_replace('.', '__', $key);
    }

    private function featureManager(): FeatureManager
    {
        return app(FeatureManager::class);
    }

    private function settingManager(): SettingManager
    {
        return app(SettingManager::class);
    }

    /** The module as this shop's shoppers see it, from the saved settings; null while no one shop is chosen. */
    public function previewUrl(): ?string
    {
        $slug = $this->shop === null ? null : Shop::query()->whereKey($this->shop)->value('slug');

        return $slug === null ? null : url('widget-preview/'.$slug).'?type=product';
    }
}
