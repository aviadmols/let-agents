<?php

namespace App\Modules\Admin\Filament\Merchant\Pages;

use App\Modules\Admin\Actions\FinishOnboarding;
use App\Modules\Admin\Actions\SaveShopProfile;
use App\Modules\Admin\Contracts\FirstStep;
use App\Modules\Admin\Contracts\ShopScan;
use App\Modules\Admin\Contracts\StorePlugin;
use App\Modules\Admin\Models\User;
use App\Modules\Admin\Support\Home;
use App\Modules\Admin\Support\Onboarding as OnboardingFacts;
use App\Modules\Tenancy\Enums\ShopPlatform;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use UnitEnum;

/**
 * A new shop's first-run screens, as the designer's panel lays them out (2026-10-18): connect the store,
 * check what Lets found, the scan beside a live try, and the first steps. A Shopify store came from its
 * app store already connected and starts at the second. The shop's panel opens here until the manager
 * goes through to the first steps (FinishOnboarding), and never again after. Every figure comes from the
 * modules (ShopFacts, ConnectionFacts, ShopScan, FirstSteps); a row with nothing real to say is left out.
 * A viewer goes through the screens and changes nothing.
 */
final class Onboarding extends Page
{
    public const STEPS = [1 => 'connect', 2 => 'check', 3 => 'scan', 4 => 'steps'];

    /** Few enough to read at a glance: the shop's own words, as tries for the search. */
    private const TRIES = 3;

    protected static string|UnitEnum|null $navigationGroup = 'onboarding';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'welcome';

    protected string $view = 'admin::merchant.onboarding';

    /** The screen being shown, 1 to 4. */
    public int $step = 1;

    /** The form to fix what Lets found is open. */
    public bool $fixing = false;

    /** WooCommerce's store chose the Shopify card: how a Shopify store connects. */
    public bool $otherPlatform = false;

    /** @var array<string, mixed> the form: name, kind, languages, sells */
    public array $data = [];

    /** @var array<string, string> */
    public array $problems = [];

    /** The connection token just made, shown once. */
    public ?string $token = null;

    /** @var array<string, mixed>|null */
    private ?array $factsCache = null;

    /** @var list<FirstStep>|null */
    private ?array $stepsCache = null;

    public static function getNavigationLabel(): string
    {
        return __('admin::onboarding.title');
    }

    public function getTitle(): string
    {
        return __('admin::onboarding.title');
    }

    /** The heading row as the design draws it: the title and line, and the four tabs beside them. */
    public function getHeader(): ?View
    {
        return view('admin::merchant.onboarding-head', ['title' => $this->getTitle(), 'step' => $this->step, 'first' => $this->firstStep()]);
    }

    public function mount(): void
    {
        $this->step = $this->firstStep();
        $this->data = app(SaveShopProfile::class)->load($this->shop());
    }

    /** A tab or a step: a Shopify store has nothing to connect. */
    public function updatedStep(): void
    {
        $this->step = max($this->firstStep(), min(4, $this->step));
        $this->otherPlatform = false;
    }

    public function firstStep(): int
    {
        return $this->shopify() ? 2 : 1;
    }

    public function shopify(): bool
    {
        return $this->shop()->platform === ShopPlatform::Shopify;
    }

    public function canEdit(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->canEditShop($this->shop());
    }

    /** Only an owner (or an operator) makes a token: it decides which store Lets reads. */
    public function canConnect(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->managesShop($this->shop());
    }

    // ------------------------------------------------------------------ 1 · the connection

    public function connected(): bool
    {
        $facts = app(OnboardingFacts::class)->connection($this->shop());

        return (bool) ($facts['connected'] ?? false);
    }

    public function hasToken(): bool
    {
        return (bool) (app(OnboardingFacts::class)->connection($this->shop())['has_token'] ?? false);
    }

    /** @return array{version: string, filename: string}|null the plugin zip a WordPress store downloads */
    public function package(): ?array
    {
        $package = $this->plugin()?->package();

        return $package === null ? null : ['version' => $package['version'], 'filename' => $package['filename']];
    }

    public function downloadPlugin(): ?BinaryFileResponse
    {
        $package = $this->plugin()?->package();

        if (! $this->canEdit() || $package === null) {
            Notification::make()->warning()->title(__('admin::shop_settings.connection.no_package'))->send();

            return null;
        }

        return response()->download($package['path'], $package['filename'], ['Content-Type' => 'application/zip', 'Cache-Control' => 'no-store']);
    }

    public function createToken(): void
    {
        $plugin = $this->plugin();

        if (! $this->canConnect() || $plugin === null || $this->shopify()) {
            Notification::make()->warning()->title(__('admin::shop_settings.owners_only'))->send();

            return;
        }

        $this->token = $plugin->issueToken($this->shop());
        Home::forget($this->shop());
    }

    /** "Connect and read the store": the store answers with its token, and the scan starts reading it. */
    public function connect(): void
    {
        if (! $this->canEdit()) {
            Notification::make()->warning()->title(__('admin::shop_settings.read_only'))->send();

            return;
        }

        $answers = $this->connected();

        if (! $answers) {
            try {
                $answers = $this->plugin()?->check($this->shop());
            } catch (Throwable $e) {
                report($e);
                $answers = false;
            }

            Home::forget($this->shop());
        }

        if ($answers !== true) {
            Notification::make()->warning()->title(__($answers === null ? 'admin::onboarding.connect.no_token' : 'admin::onboarding.connect.not_yet'))->send();

            return;
        }

        $this->startScan();
        $this->step = 2;
    }

    // ------------------------------------------------------------------ 2 · what Lets found

    /**
     * What Lets found, a row each, only what something real says.
     *
     * @return list<array{key: string, label: string, value: string}>
     */
    public function found(): array
    {
        $shop = $this->shop();
        $facts = $this->facts();
        $profile = (array) ($shop->profile ?? []);
        $t = fn (string $key, array $params = []): string => __('admin::onboarding.check.'.$key, $params);
        $rows = [];

        $rows[] = ['key' => 'name', 'label' => $t('name'), 'value' => (string) $shop->name];

        $store = [$shop->platform->label()];
        foreach (['products', 'pages'] as $key) {
            if ((int) ($facts[$key] ?? 0) > 0) {
                $store[] = trans_choice('admin::onboarding.check.'.$key, (int) $facts[$key], ['count' => number_format((int) $facts[$key])]);
            }
        }
        $rows[] = ['key' => 'store', 'label' => $t('store'), 'value' => implode(' · ', $store)];

        $languages = array_values(array_unique([(string) $shop->content_locale, ...array_map('strval', (array) ($profile['languages'] ?? []))]));
        $rows[] = ['key' => 'languages', 'label' => $t('languages'), 'value' => implode(' · ', array_map(
            fn (string $code, int $i): string => $i === 0 ? $t('main', ['language' => SaveShopProfile::LANGUAGES[$code] ?? $code]) : (SaveShopProfile::LANGUAGES[$code] ?? $code),
            $languages,
            array_keys($languages),
        ))];

        // what the shop has said about itself, only once it has
        if (in_array($profile['kind'] ?? null, SaveShopProfile::KINDS, true)) {
            $rows[] = ['key' => 'site', 'label' => $t('site'), 'value' => (string) __('admin::onboarding.check.kinds.'.$profile['kind'])];
        }

        $sells = array_values(array_filter(array_map('strval', (array) ($profile['sells'] ?? [])), fn (string $s): bool => $s !== ''));
        if ($sells !== []) {
            $rows[] = ['key' => 'sells', 'label' => $t('sells'), 'value' => implode(' · ', $sells)];
        }

        $seen = $facts['seen'] ?? null;
        if (is_array($seen) && is_string($seen['text'] ?? null) && $seen['text'] !== '') {
            $pages = (int) ($seen['pages'] ?? 0);
            $rows[] = ['key' => 'seen', 'label' => $t('seen'), 'value' => $seen['text'].($pages > 0 ? ' · '.trans_choice('admin::onboarding.check.seen_on', $pages, ['count' => number_format($pages)]) : '')];
        }

        return $rows;
    }

    /** Nothing read from the store yet, while the scan reads it: the screen says so and looks again. */
    public function reading(): bool
    {
        $progress = $this->progress();

        return (int) ($this->facts()['products'] ?? 0) === 0 && $progress !== null && ($progress['parts']['products'] ?? null) !== ShopScan::DONE;
    }

    public function fix(): void
    {
        $this->fixing = ! $this->fixing;
    }

    /** "Right, start the scan". */
    public function confirm(): void
    {
        if ($this->canEdit()) {
            $this->startScan();
        }

        $this->step = 3;
    }

    /** "Save and start the scan": the same saving as Settings · General. */
    public function saveProfile(): void
    {
        if (! $this->canEdit()) {
            Notification::make()->warning()->title(__('admin::shop_settings.read_only'))->send();

            return;
        }

        $action = app(SaveShopProfile::class);
        $this->problems = $action->problems($this->data);

        if ($this->problems !== []) {
            Notification::make()->warning()->title(__('admin::onboarding.form.problems'))->send();

            return;
        }

        DB::transaction(fn () => $action->handle($this->shop(), $this->data));
        $this->data = $action->load($this->shop()->refresh());
        Home::forget($this->shop());
        Notification::make()->success()->title(__('admin::onboarding.form.saved'))->send();

        $this->fixing = false;
        $this->confirm();
    }

    /** A problem the last save found in this field. */
    public function problem(string $field): ?string
    {
        return $this->problems[$field] ?? null;
    }

    // ------------------------------------------------------------------ 3 · the scan, and a try

    /** @return array{finished: bool, parts: array<string, string>}|null */
    public function progress(): ?array
    {
        try {
            return app(OnboardingFacts::class)->scan()?->progress($this->shop());
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    public function scanning(): bool
    {
        $progress = $this->progress();

        return $progress !== null && ! $progress['finished'];
    }

    /**
     * The scan's rows, as the design draws them: what it reads, how many so far, of how many.
     *
     * @return list<array{key: string, title: string, sub: string, n: int, unit: string, state: string}>
     */
    public function scanRows(): array
    {
        $facts = $this->facts();
        $parts = $this->progress()['parts'] ?? null;
        $of = fn (string $key): ?string => isset($facts[$key]) && (int) $facts[$key] > 0 ? __('admin::onboarding.scan.of', ['count' => number_format((int) $facts[$key])]) : null;

        // part => the count it shows, and what it is counted against (a module that is not there: no row)
        $rows = [
            'products' => ['products', null],
            'images' => ['pictures_read', $of('products')],
            'pages' => ['pages_read', $of('pages')],
            'tags' => ['tags', null],
            'knowledge' => ['answers', null],
        ];

        $out = [];
        foreach ($rows as $part => [$key, $against]) {
            if (! array_key_exists($key, $facts)) {
                continue;
            }

            $n = (int) $facts[$key];
            $out[] = [
                'key' => $part,
                'title' => (string) __("admin::onboarding.scan.rows.{$part}.title"),
                'sub' => (string) __("admin::onboarding.scan.rows.{$part}.sub"),
                'n' => $n,
                'unit' => $against ?? (string) __("admin::onboarding.scan.rows.{$part}.unit"),
                // no scan this week: what is there was read before
                'state' => $parts[$part] ?? ($n > 0 ? ShopScan::DONE : ShopScan::WAITING),
            ];
        }

        return $out;
    }

    /** @return list<array{title: string, text: string}> what the scan reported, a line a part */
    public function learned(): array
    {
        try {
            $learned = app(OnboardingFacts::class)->scan()?->learned($this->shop()) ?? [];
        } catch (Throwable $e) {
            report($e);
            $learned = [];
        }

        return array_values(array_map(fn (array $l): array => ['title' => (string) __('admin::onboarding.scan.learned.'.$l['part']), 'text' => $l['text']], array_filter(
            $learned,
            fn (array $l): bool => in_array($l['part'] ?? null, ShopScan::PARTS, true) && trim((string) ($l['text'] ?? '')) !== '',
        )));
    }

    public function scanAgain(): void
    {
        if (! $this->canEdit()) {
            return;
        }

        $started = $this->startScan();
        $note = Notification::make()->title(__($started ? 'admin::onboarding.scan.started' : 'admin::onboarding.scan.too_soon'));
        ($started ? $note->success() : $note->warning())->send();
    }

    /** How many products the store's search holds now, when the search said. */
    public function searchable(): ?int
    {
        $facts = $this->facts();

        return isset($facts['searchable']) ? (int) $facts['searchable'] : null;
    }

    /**
     * The store as a shopper sees it, in the team's preview while it is not live yet (the first step
     * "see it as a shopper" has the address): the real drawer, on the real store, its tries not counted
     * as a shopper's view. Null when the store has no address yet.
     */
    public function tryUrl(): ?string
    {
        $look = collect($this->steps())->firstWhere('key', 'look');

        return $look instanceof FirstStep && is_string($look->url) && $look->url !== '' ? $look->url : null;
    }

    /**
     * Where a Shopify store switches Lets on in its theme, while it is not live: there the search is in the
     * store only once the app embed is on, so the try asks for it first (the first step "on" has the link).
     */
    public function embedUrl(): ?string
    {
        $on = collect($this->steps())->firstWhere('key', 'on');

        return $this->shopify() && $on instanceof FirstStep && ! $on->done && $on->external && is_string($on->url) && $on->url !== '' ? $on->url : null;
    }

    /** Whether the store is live already: a try is then the shoppers' own search, not a preview. */
    public function live(): bool
    {
        $on = collect($this->steps())->firstWhere('key', 'on');

        return $on instanceof FirstStep && $on->done;
    }

    /** @return list<string> the shop's own words for what it sells, as tries */
    public function tries(): array
    {
        return array_slice(array_values(array_filter(array_map('strval', (array) (($this->shop()->profile ?? [])['sells'] ?? [])), fn (string $s): bool => $s !== '')), 0, self::TRIES);
    }

    /** The manager opened the store from the try: the step "see it as a shopper" is behind them. */
    public function looked(): void
    {
        if ($this->canEdit()) {
            app(Home::class)->markDone($this->shop(), ShopOverview::LOOK_DONE, Auth::id());
        }
    }

    /** "Continue": the first steps, and the shop is set up. */
    public function toFirstSteps(): void
    {
        if ($this->canEdit()) {
            app(FinishOnboarding::class)->handle($this->shop());
        }

        $this->step = 4;
    }

    // ------------------------------------------------------------------ 4 · the first steps

    /** @return list<FirstStep> the same steps the home shows */
    public function steps(): array
    {
        return $this->stepsCache ??= app(Home::class)->steps($this->shop());
    }

    /** "2,160 products, 37 pages and posts, 9,840 tags and 24 ready answers": only what was counted. */
    public function summary(): ?string
    {
        $facts = $this->facts();
        $parts = [];

        foreach (['products' => 'products', 'pages' => 'pages', 'tags' => 'tags', 'answers' => 'answers'] as $key => $word) {
            if ((int) ($facts[$key] ?? 0) > 0) {
                $parts[] = trans_choice('admin::onboarding.steps.'.$word, (int) $facts[$key], ['count' => number_format((int) $facts[$key])]);
            }
        }

        if ($parts === []) {
            return null;
        }

        $last = array_pop($parts);
        $list = $parts === [] ? $last : implode(', ', $parts).__('admin::onboarding.steps.and').$last;

        return __('admin::onboarding.steps.counts', ['list' => $list]);
    }

    /** "Go to the home page": the shop is set up, and the panel opens on its home from now on. */
    public function finish(): void
    {
        if ($this->canEdit()) {
            app(FinishOnboarding::class)->handle($this->shop());
        }

        $this->redirect(ShopOverview::getUrl());
    }

    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    public function facts(): array
    {
        return $this->factsCache ??= app(OnboardingFacts::class)->facts($this->shop());
    }

    private function startScan(): bool
    {
        try {
            return (bool) app(OnboardingFacts::class)->scan()?->start($this->shop());
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private function plugin(): ?StorePlugin
    {
        return app()->bound(StorePlugin::class) ? app(StorePlugin::class) : null;
    }

    private function shop(): Shop
    {
        /** @var Shop $shop */
        $shop = Filament::getTenant();

        return $shop;
    }
}
