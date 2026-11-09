<?php

namespace App\Modules\Widget\Filament\Merchant\Pages;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Localization\Money;
use App\Core\Plans\PlanCeilings;
use App\Core\Settings\InvalidSettingValue;
use App\Core\Settings\ValueSource;
use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Retrieval\Models\RetrievalImage;
use App\Modules\Tenancy\Models\Shop;
use App\Modules\Widget\Support\OpeningHours as Hours;
use App\Modules\Widget\Support\PageCache;
use App\Modules\Widget\Support\ShopWeek;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use UnitEnum;

/**
 * What shoppers see, as the designer's redesign lays it out (Tomer, 11.2026): everything Lets shows on the store, in
 * one place, beside a live preview that follows every change. The look (Lets' own or the store's) and how photos sit;
 * the search window (suggestions while typing, answers, the camera, prices); saving and comparing; and what happens
 * when the assistant does not know (WhatsApp, its number, the hours). Each control saves the moment it changes, the
 * shop's own word kept only where it differs from what it would inherit, as the settings pages keep it; a switch the
 * plan holds stays as it is. The settings are the modules' own keys (search.*, assistant.*, shortlist.*, widget.*), read
 * and written through Features and Settings; the detailed settings pages stay, behind "More options".
 */
final class WhatShoppersSee extends Page
{
    /** Each switch: the keys it turns. Hand-over is WhatsApp on, and the search's "ask the team" to it. */
    public const SWITCHES = [
        'suggest' => ['feature:search.suggest'],
        'answers' => ['feature:assistant.on_search'],
        'photos' => ['feature:search.photos'],
        'price' => ['setting:search.show_price'],
        'heart' => ['feature:shortlist.wishlist'],
        'compare' => ['feature:shortlist.compare'],
        'whatsapp' => ['feature:widget.whatsapp', 'feature:assistant.search_whatsapp'],
    ];

    /** Each choice: its key and the values the screen offers (screen word => stored value). */
    public const CHOICES = [
        'look' => ['setting:search.theme', ['lets' => 'lets', 'store' => 'store']],
        'fit' => ['setting:search.image_fit', ['fill' => 'cover', 'all' => 'frame']],
    ];

    /** The WhatsApp number as typed; saved on "Save" or leaving the field. */
    public string $number = '';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEye;

    protected static string|UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'see';

    protected string $view = 'widget::merchant.see';

    public static function getNavigationLabel(): string
    {
        return __('widget::see.nav');
    }

    public function getTitle(): string
    {
        return __('widget::see.title');
    }

    public function getHeader(): ?View
    {
        return view('admin::merchant.screen-head', ['title' => $this->getTitle(), 'lede' => __('widget::see.lede'), 'aside' => __('widget::see.autosave')]);
    }

    public function mount(): void
    {
        $saved = (string) (Settings::overrideFor('widget.whatsapp_number', $this->shopId()) ?? '');
        $this->number = $saved === '' ? '' : '+'.ltrim($saved, '+');
    }

    /** @return array<string, bool> each switch as the store has it now */
    public function switches(): array
    {
        $on = [];
        foreach (self::SWITCHES as $name => $keys) {
            $on[$name] = collect($keys)->every(fn (string $key): bool => $this->value($key) === true);
        }
        // handing over needs a number, and the search sending unanswered questions to the team
        $on['whatsapp'] = $on['whatsapp'] && $this->hasNumber() && Settings::get('assistant.no_answer', $this->shopId()) === 'team';

        return $on;
    }

    /** @return array<string, string> each choice, in the screen's words */
    public function choices(): array
    {
        $out = [];
        foreach (self::CHOICES as $name => [$key, $values]) {
            $out[$name] = (string) (array_search((string) $this->value($key), $values, true) ?: array_key_first($values));
        }

        return $out;
    }

    /**
     * The controls the shop's plan holds: shown as they are, not changed here, "Requires upgrade" beside them. Held is
     * what the plan keeps from the shop (a feature it does not include, a choice it makes), whether or not the shop's
     * value happens to be the plan's now: Starter with the Lets look still cannot pick the store's own.
     *
     * @return list<string>
     */
    public function held(): array
    {
        $shop = $this->shopId();
        $ceilings = app(PlanCeilings::class)->for($shop);
        $held = [];
        foreach ([...array_map(fn (array $keys): array => $keys, self::SWITCHES), ...array_map(fn (array $c): array => [$c[0]], self::CHOICES)] as $name => $keys) {
            foreach ($keys as $source) {
                [$kind, $key] = explode(':', $source, 2);
                $ceiling = $ceilings[$kind === 'feature' ? 'features' : 'settings'][$key] ?? null;
                $keeps = $ceiling === false || is_string($ceiling);
                if ($keeps || ($kind === 'feature' ? Features::source($key, $shop) : Settings::source($key, $shop)) === ValueSource::Plan) {
                    $held[] = (string) $name;
                }
            }
        }

        return array_values(array_unique($held));
    }

    /** A switch flipped: saved at once. */
    public function turn(string $name): void
    {
        if (! isset(self::SWITCHES[$name]) || in_array($name, $this->held(), true)) {
            return;
        }
        $on = ! ($this->switches()[$name] ?? false);

        if ($name === 'whatsapp') {
            if ($on && ! $this->hasNumber()) {
                Notification::make()->warning()->title(__('widget::see.whatsapp.needs_number'))->send();

                return;
            }
            // off: only the search stops handing over; the WhatsApp button on product pages is its own page's word
            $on ? $this->putAll(['feature:widget.whatsapp' => true, 'feature:assistant.search_whatsapp' => true, 'setting:assistant.no_answer' => 'team'])
                : $this->put('feature:assistant.search_whatsapp', false);
        } else {
            foreach (self::SWITCHES[$name] as $key) {
                $this->put($key, $on);
            }
        }

        // the camera needs the pictures read: turning it on starts that reading, as the search settings page does
        if ($name === 'photos' && $on && ! Features::enabled('retrieval.image_index', $this->shopId())) {
            Features::override('retrieval.image_index', true, $this->shopId());
        }
        $this->changed();
    }

    /** A choice made (the look, the photos): saved at once. */
    public function choose(string $name, string $value): void
    {
        if (! isset(self::CHOICES[$name]) || in_array($name, $this->held(), true)) {
            return;
        }
        [$key, $values] = self::CHOICES[$name];
        if (! isset($values[$value])) {
            return;
        }
        $this->put($key, $values[$value]);
        $this->changed();
    }

    /** The WhatsApp number: international digits, 8–15 of them; empty only while hand-over is off. */
    public function saveNumber(): void
    {
        $typed = trim($this->number);
        $digits = $typed === '' ? '' : WhatsappSettings::international($typed);
        if ($typed !== '' && (strlen($digits) < 8 || strlen($digits) > 15)) {
            Notification::make()->warning()->title(__('widget::see.whatsapp.bad_number'))->send();

            return;
        }
        if ($digits === '' && $this->switches()['whatsapp']) {
            Notification::make()->warning()->title(__('widget::see.whatsapp.keep_number'))->send();

            return;
        }
        try {
            if ($digits === '') {
                // no number, no WhatsApp button anywhere (the WhatsApp page refuses one without a number)
                Settings::clear('widget.whatsapp_number', $this->shopId());
                $this->put('feature:widget.whatsapp', false);
            } else {
                Settings::set('widget.whatsapp_number', $digits, $this->shopId());
            }
        } catch (InvalidSettingValue) {
            Notification::make()->warning()->title(__('widget::see.whatsapp.bad_number'))->send();

            return;
        }
        $this->number = $digits === '' ? '' : '+'.$digits;
        $this->changed();
    }

    /** "Sun–Thu 9:00–18:00 · Fri 9:00–13:00": the answering hours, days with the same hours together. */
    public function hours(): string
    {
        $week = ShopWeek::for($this->shopId());
        $runs = [];
        foreach (Hours::DAYS as $day) {
            $range = (string) ($week[$day] ?? '');
            $last = count($runs) - 1;
            if ($last >= 0 && $runs[$last]['range'] === $range) {
                $runs[$last]['to'] = $day;
            } else {
                $runs[] = ['from' => $day, 'to' => $day, 'range' => $range];
            }
        }
        $open = array_values(array_filter($runs, fn (array $r): bool => $r['range'] !== ''));
        if ($open === []) {
            return __('widget::see.hours.closed');
        }
        $short = fn (string $day): string => __('widget::see.days.'.$day);
        // "09:00" reads "9:00"; "00:30" stays "0:30"
        $time = fn (string $t): string => (string) preg_replace('/^0(\d)/', '$1', $t);

        return implode(' · ', array_map(function (array $r) use ($short, $time): string {
            [$from, $until] = array_pad(explode('-', $r['range'], 2), 2, '');

            // the hours read left to right in Hebrew too ("9:00–18:00", not "18:00–9:00"): isolated
            return ($r['from'] === $r['to'] ? $short($r['from']) : $short($r['from']).'–'.$short($r['to'])).' '."\u{2066}".$time($from).'–'.$time($until)."\u{2069}";
        }, $open));
    }

    /** How many of the store's photos were read already, for "Search by photo". */
    public function photosRead(): int
    {
        return (int) app(TenantContext::class)->run($this->shopId(), fn (): int => RetrievalImage::query()->whereNotNull('caption')->count());
    }

    /**
     * What the preview shows: the store's name and address, and two of its products with their pictures and prices.
     *
     * @return array{name: string, domain: string, hero: string|null, products: list<array{title: string, picture: string|null, price: string|null}>, chips: list<string>}
     */
    public function preview(): array
    {
        $shop = $this->shop();
        $products = app(TenantContext::class)->run($this->shopId(), fn () => CatalogProduct::query()->active()->whereNotNull('image_url')->where('in_stock', true)->orderByDesc('updated_at')->orderBy('id')->limit(3)->get(['title', 'image_url', 'price']));
        $currency = (string) ($shop->currency ?: 'ILS');

        return [
            'name' => (string) $shop->name,
            'domain' => preg_replace('/^www\./', '', (string) $shop->domain) ?: (string) $shop->name,
            'hero' => $products->first()?->image_url,
            'products' => $products->skip(1)->take(2)->values()->map(fn (CatalogProduct $p): array => [
                'title' => (string) $p->title,
                'picture' => $p->image_url,
                'price' => $p->price === null || $p->price === '' ? null : Money::price((float) $p->price, $currency),
            ])->all(),
            'chips' => $products->take(3)->map(fn (CatalogProduct $p): string => mb_strtolower(implode(' ', array_slice(preg_split('/\s+/u', trim((string) $p->title)) ?: [], 0, 2))))->unique()->values()->all(),
        ];
    }

    /** The detailed settings page of a group, for "More options", when the panel has it. */
    public function more(string $route): ?string
    {
        $name = 'filament.merchant.pages.settings.'.$route;

        return Route::has($name) ? route($name, ['tenant' => $this->shop()]) : null;
    }

    public function hoursUrl(): string
    {
        return OpeningHours::getUrl();
    }

    private function hasNumber(): bool
    {
        return strlen(preg_replace('/\D/', '', (string) (Settings::overrideFor('widget.whatsapp_number', $this->shopId()) ?? '')) ?? '') >= 8;
    }

    private function value(string $source): mixed
    {
        [$kind, $key] = explode(':', $source, 2);

        return $kind === 'feature' ? Features::enabled($key, $this->shopId()) : Settings::get($key, $this->shopId());
    }

    /** @param array<string, mixed> $values */
    private function putAll(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value);
        }
    }

    /** The shop's own word only where it differs from what it would inherit (EditsShopSettings keeps it so). */
    private function put(string $source, mixed $value): void
    {
        [$kind, $key] = explode(':', $source, 2);
        $shop = $this->shopId();
        if ($kind === 'feature') {
            Features::clearOverride($key, $shop);
            if (Features::enabled($key, $shop) !== (bool) $value) {
                Features::override($key, (bool) $value, $shop);
            }

            return;
        }
        Settings::clear($key, $shop);
        if (Settings::normalize($key, $value) !== Settings::get($key, $shop)) {
            Settings::set($key, $value, $shop);
        }
    }

    private function changed(): void
    {
        // product pages show WhatsApp and the camera: their kept pages are read again
        PageCache::forgetShop($this->shopId());
        Notification::make()->success()->title(__('widget::see.saved'))->send();
    }

    private function shop(): Shop
    {
        /** @var Shop $shop */
        $shop = Filament::getTenant();

        return $shop;
    }

    private function shopId(): string
    {
        return (string) $this->shop()->id;
    }
}
