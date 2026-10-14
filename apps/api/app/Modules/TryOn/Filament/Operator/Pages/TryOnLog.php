<?php

namespace App\Modules\TryOn\Filament\Operator\Pages;

use App\Core\Facades\Settings;
use App\Core\Features\FeatureManager;
use App\Core\Settings\InvalidSettingValue;
use App\Core\Settings\SettingDefinition;
use App\Core\Settings\SettingManager;
use App\Core\Settings\SettingRegistry;
use App\Core\Settings\SettingScope;
use App\Core\Settings\SettingType;
use App\Core\Tenancy\NeedsShopContext;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\TryOn\Models\TryOnJob;
use App\Modules\TryOn\Models\TryOnPhoto;
use App\Modules\TryOn\Models\TryOnStep;
use App\Modules\TryOn\Support\Files;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Everything about Try-On in one place, for the operator (docs/try-on.md §9): whether it is on for
 * this shop and for all of them, what the month cost, the team's test link; every try with each
 * step's prompt, answer, pictures, model and cost; every photo check, refused ones too; which
 * products the scan marked and as what; and every Try-On setting, edited here with the same rules
 * as the Configuration screen (the Core registries). The shop manager sees only counts (TryOnSummary).
 */
final class TryOnLog extends Page
{
    use NeedsShopContext;

    public const TABS = ['overview', 'tries', 'photos', 'products', 'settings'];

    private const FEATURE = 'try_on.enabled';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'try_on';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'try-on/log';

    protected string $view = 'try_on::operator.log';

    #[Url]
    public string $tab = 'overview';

    #[Url]
    public ?string $job = null;

    #[Url]
    public ?string $photo = null;

    #[Url]
    public string $productClass = '';

    /**
     * Setting name (model_jewelry, not try_on.model_jewelry: Livewire reads a dot in a bound key as
     * nesting, and the field would write where save() never reads) => the value in the form ('' or null: inherit).
     *
     * @var array<string, string|bool|null>
     */
    public array $values = [];

    public static function getNavigationLabel(): string
    {
        return __('try_on::hub.nav');
    }

    public function getTitle(): string
    {
        return __('try_on::hub.title');
    }

    public function getSubheading(): ?string
    {
        return __('try_on::hub.subheading');
    }

    public function mount(): void
    {
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'overview';
        $this->fillValues();
    }

    public function show(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'overview';
    }

    // ------------------------------------------------------------------ overview

    /**
     * Whether Try-On is on here, and where that comes from: this shop's own choice, the default for
     * every shop, or the module's default.
     *
     * @return array{shop: string|null, on: bool, source: string, global: bool, global_set: bool}
     */
    public function status(): array
    {
        $shop = $this->shopId();
        $features = app(FeatureManager::class);
        $global = $features->overrideFor(self::FEATURE);

        return [
            'shop' => $shop,
            'on' => $features->enabled(self::FEATURE, $shop),
            'source' => $features->source(self::FEATURE, $shop)->value,
            'global' => $features->enabled(self::FEATURE),
            'global_set' => $global !== null,
        ];
    }

    /** On or off for this shop only; null returns it to the default for every shop. */
    public function setShop(?bool $on): void
    {
        $shop = $this->shopId();

        if ($shop === null) {
            return;
        }

        $on === null ? app(FeatureManager::class)->clearOverride(self::FEATURE, $shop) : app(FeatureManager::class)->override(self::FEATURE, $on, $shop);
        Notification::make()->success()->title(__('try_on::hub.saved'))->send();
    }

    /** The default for every shop that has not chosen. */
    public function setGlobal(bool $on): void
    {
        app(FeatureManager::class)->override(self::FEATURE, $on);
        Notification::make()->success()->title(__('try_on::hub.saved'))->send();
    }

    /** @return array{count: int, ready: int, failed: int, photos: int, refused: int, usd: float, cap: float, share: int, usd_text: string} */
    public function month(): array
    {
        $empty = ['count' => 0, 'ready' => 0, 'failed' => 0, 'photos' => 0, 'refused' => 0, 'usd' => 0.0, 'cap' => 0.0, 'share' => 0, 'usd_text' => '0'];

        return $this->inShop(function (): array {
            $since = now()->startOfMonth();
            $jobs = TryOnJob::query()->where('created_at', '>=', $since);
            $photos = TryOnPhoto::query()->where('created_at', '>=', $since);
            $usd = (float) (clone $jobs)->sum('cost_usd') + (float) (clone $photos)->sum('cost_usd');
            $cap = (float) Settings::get('try_on.monthly_cap_usd', (string) $this->shopId());

            return [
                'count' => (clone $jobs)->count(),
                'ready' => (clone $jobs)->where('status', TryOnJob::READY)->count(),
                'failed' => (clone $jobs)->where('status', TryOnJob::FAILED)->count(),
                'photos' => (clone $photos)->count(),
                'refused' => (clone $photos)->where('status', TryOnPhoto::REJECTED)->count(),
                'usd' => $usd,
                'cap' => $cap,
                'share' => $cap > 0 ? (int) min(100, round($usd / $cap * 100)) : 0,
                'usd_text' => number_format($usd, 4),
            ];
        }) ?? $empty;
    }

    /** Kept for the month line the screen always had. @return array{count: int, ready: int, usd: string} */
    public function monthLine(): array
    {
        $month = $this->month();

        return ['count' => $month['count'], 'ready' => $month['ready'], 'usd' => $month['usd_text']];
    }

    /** Why photos were refused this month, the most common first. @return array<string, int> */
    public function refusals(): array
    {
        return $this->inShop(fn (): array => TryOnPhoto::query()->where('created_at', '>=', now()->startOfMonth())->where('status', TryOnPhoto::REJECTED)
            ->selectRaw('reject_code, count(*) as n')->groupBy('reject_code')->orderByDesc('n')->pluck('n', 'reject_code')
            ->mapWithKeys(fn ($n, $code): array => [(string) $code => (int) $n])->all()) ?? [];
    }

    /**
     * How the team tries Try-On on its own store before it is on for shoppers: the parameters to add
     * to any product page's address, with this store's preview key.
     *
     * @return array{params: string, example: string|null}|null
     */
    public function testLink(): ?array
    {
        return $this->inShop(function (): ?array {
            $connection = StoreConnection::query()->first();

            if ($connection === null) {
                return null;
            }

            $params = 'let_agents_preview='.$connection->previewKey().'&let_agents_tryon=1';

            return ['params' => $params, 'example' => $connection->site_url ? rtrim((string) $connection->site_url, '/').'/?'.$params : null];
        });
    }

    /** Deletes the pictures past the shop's days now, as the nightly run does (try-on:prune). */
    public function pruneNow(): void
    {
        Artisan::call('try-on:prune');
        Notification::make()->success()->title(__('try_on::hub.pruned'))->send();
    }

    // ------------------------------------------------------------------ tries

    /** @return Collection<int, TryOnJob> */
    public function jobs(int $limit = 50): Collection
    {
        // A try past its minutes shows as ended, here too.
        return $this->inShop(fn () => TryOnJob::query()->with('product:id,title')->latest()->limit($limit)->get()->each(fn (TryOnJob $job) => $job->endIfStuck())) ?? collect();
    }

    /**
     * Why a try failed, in words: the reason it ended with, and for a refused picture what the checker
     * saw (its decision and the faults it listed on the last attempt), for a model that did not answer
     * which step and what it said.
     */
    public function failureWhy(TryOnJob $job): ?string
    {
        if ($job->status !== TryOnJob::FAILED || $job->failure === null) {
            return null;
        }

        $last = $this->inShop(fn () => TryOnStep::query()->where('job_id', $job->id)->whereIn('status', ['refused', 'failed'])->latest('created_at')->latest('id')->first());
        $detail = '';

        if ($job->failure === 'check_failed' && $last !== null) {
            $decision = (string) ($last->output['decision'] ?? '');
            $faults = implode(', ', array_slice(array_map('strval', (array) ($last->output['artifacts'] ?? [])), 0, 3));
            $detail = trim(self::words('checks.'.$decision, $decision).($faults !== '' ? ' ('.$faults.')' : ''));
        } elseif ($job->failure === 'model_failed' && $last !== null) {
            $detail = trim($last->step.': '.(string) $last->error);
        }

        return (string) __('try_on::hub.failures.'.$job->failure, ['detail' => $detail, 'minutes' => (string) TryOnJob::MAX_MINUTES]) ?: $job->failure;
    }

    /** Why tries failed this month, the most common first. @return array<string, int> */
    public function failures(): array
    {
        return $this->inShop(fn (): array => TryOnJob::query()->where('created_at', '>=', now()->startOfMonth())->where('status', TryOnJob::FAILED)
            ->selectRaw('failure, count(*) as n')->groupBy('failure')->orderByDesc('n')->pluck('n', 'failure')
            ->mapWithKeys(fn ($n, $code): array => [(string) $code => (int) $n])->all()) ?? [];
    }

    public static function failureName(string $code): string
    {
        return self::words('failure_names.'.$code, $code);
    }

    private static function words(string $key, string $fallback): string
    {
        return Lang::has('try_on::hub.'.$key) ? (string) __('try_on::hub.'.$key) : $fallback;
    }

    public function open(string $id): void
    {
        $this->tab = 'tries';
        $this->job = $this->job === $id ? null : $id;
    }

    /**
     * The open try, with the photo's check and its own steps in order, and each picture as a signed address.
     *
     * @return array{job: TryOnJob, steps: list<array<string, mixed>>, photo: string|null, result: string|null}|null
     */
    public function current(): ?array
    {
        if ($this->job === null) {
            return null;
        }

        return $this->inShop(function (): ?array {
            $job = TryOnJob::query()->with(['product:id,title,image_url', 'photo'])->find($this->job);

            if ($job === null) {
                return null;
            }

            $steps = TryOnStep::query()->where(fn ($q) => $q->where('job_id', $job->id)->orWhere(fn ($p) => $p->whereNull('job_id')->where('photo_id', $job->photo_id)))
                ->orderBy('created_at')->orderBy('id')->get()->map(fn (TryOnStep $s): array => self::step($s))->all();

            return ['job' => $job, 'steps' => $steps, 'photo' => Files::url($job->photo?->path, 30), 'result' => Files::url($job->result_path, 30)];
        });
    }

    // ------------------------------------------------------------------ photos

    /** @return Collection<int, TryOnPhoto> */
    public function photos(): Collection
    {
        return $this->inShop(fn () => TryOnPhoto::query()->latest()->limit(60)->get()) ?? collect();
    }

    public function openPhoto(string $id): void
    {
        $this->photo = $this->photo === $id ? null : $id;
    }

    /** @return array{photo: TryOnPhoto, url: string|null, steps: list<array<string, mixed>>}|null */
    public function currentPhoto(): ?array
    {
        if ($this->photo === null) {
            return null;
        }

        return $this->inShop(function (): ?array {
            $photo = TryOnPhoto::query()->find($this->photo);

            if ($photo === null) {
                return null;
            }

            $steps = TryOnStep::query()->where('photo_id', $photo->id)->whereNull('job_id')->orderBy('created_at')->orderBy('id')->get()
                ->map(fn (TryOnStep $s): array => self::step($s))->all();

            return ['photo' => $photo, 'url' => Files::url($photo->path, 30), 'steps' => $steps];
        });
    }

    public static function refusal(?string $code): string
    {
        $key = 'try_on::try_on.rejected.'.$code;

        return $code !== null && Lang::has($key) ? (string) __($key) : (string) $code;
    }

    // ------------------------------------------------------------------ products

    /** How many products the scan marked as each class, and as not tryable. @return array<string, int> */
    public function classCounts(): array
    {
        return $this->inShop(fn (): array => $this->classFacts()->selectRaw('value_text, count(*) as n')->groupBy('value_text')->orderByDesc('n')
            ->pluck('n', 'value_text')->mapWithKeys(fn ($n, $class): array => [(string) $class => (int) $n])->all()) ?? [];
    }

    public function filterClass(string $class): void
    {
        $this->productClass = $this->productClass === $class ? '' : $class;
    }

    /** @return Collection<int, EnrichmentFact> the marked products, of the class chosen */
    public function markedProducts(): Collection
    {
        return $this->inShop(fn () => $this->classFacts()->with('product:id,title,url,image_url,external_id')
            ->when($this->productClass !== '', fn ($q) => $q->where('value_text', $this->productClass))
            ->latest('id')->limit(120)->get()) ?? collect();
    }

    public static function className(string $class): string
    {
        $key = 'try_on::hub.classes.'.$class;

        return Lang::has($key) ? (string) __($key) : $class;
    }

    // ------------------------------------------------------------------ settings

    /** @return array{shop: list<SettingDefinition>, global: list<SettingDefinition>} */
    public function definitions(): array
    {
        $all = array_values(app(SettingRegistry::class)->forModule('try_on'));

        return [
            'shop' => array_values(array_filter($all, fn (SettingDefinition $d): bool => $d->scope === SettingScope::Shop)),
            'global' => array_values(array_filter($all, fn (SettingDefinition $d): bool => $d->scope !== SettingScope::Shop)),
        ];
    }

    /** What the setting is when this screen leaves it empty: the value it inherits. */
    public function inherited(SettingDefinition $definition): string
    {
        $shop = $definition->isShopOverridable() ? $this->shopId() : null;
        $value = $shop !== null ? app(SettingManager::class)->get($definition->key()) : $definition->default;

        return is_bool($value) ? ($value ? '✓' : '—') : (string) $value;
    }

    public function save(): void
    {
        $errors = [];
        $clean = [];

        foreach ([...$this->definitions()['shop'], ...$this->definitions()['global']] as $definition) {
            $raw = $this->values[$definition->name] ?? null;

            if ($raw === null || $raw === '') {
                $clean[$definition->key()] = null;

                continue;
            }

            try {
                $clean[$definition->key()] = $definition->normalize($raw);
            } catch (InvalidSettingValue $e) {
                $errors['values.'.$definition->name] = $e->translated();
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($clean): void {
            foreach ([...$this->definitions()['shop'], ...$this->definitions()['global']] as $definition) {
                // A shop's own value where the setting is the shop's and a shop is chosen; else for every shop.
                $scope = $definition->isShopOverridable() ? $this->shopId() : null;
                $value = $clean[$definition->key()];
                $value === null ? app(SettingManager::class)->clear($definition->key(), $scope) : app(SettingManager::class)->set($definition->key(), $value, $scope);
            }
        });

        $this->fillValues();
        Notification::make()->success()->title(__('try_on::hub.saved'))->send();
    }

    /** @return array<string, string> option => its words, for an enum setting */
    public static function options(SettingDefinition $definition): array
    {
        $out = [];

        foreach ($definition->options as $option) {
            $key = "{$definition->module}::settings.{$definition->name}.options.{$option}";
            $out[$option] = Lang::has($key) ? (string) __($key) : $option;
        }

        return $out;
    }

    public static function unit(SettingDefinition $definition): ?string
    {
        return $definition->unit !== null && Lang::has("core::settings.units.{$definition->unit}") ? (string) __("core::settings.units.{$definition->unit}") : null;
    }

    public static function isBool(SettingDefinition $definition): bool
    {
        return $definition->type === SettingType::Bool;
    }

    private function fillValues(): void
    {
        $values = [];

        foreach ([...$this->definitions()['shop'], ...$this->definitions()['global']] as $definition) {
            $scope = $definition->isShopOverridable() ? $this->shopId() : null;
            $override = app(SettingManager::class)->overrideFor($definition->key(), $scope);
            $values[$definition->name] = $override === null ? '' : (is_bool($override) ? $override : (string) $override);
        }

        $this->values = $values;
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string, mixed> */
    private static function step(TryOnStep $s): array
    {
        return [
            'step' => $s->step, 'attempt' => $s->attempt, 'status' => $s->status, 'provider' => $s->provider, 'model' => $s->model,
            'tokens' => $s->tokens_in.' / '.$s->tokens_out, 'seconds' => (float) $s->seconds, 'cost' => (float) $s->cost_usd,
            'prompt' => $s->prompt, 'input' => $s->input, 'output' => $s->output, 'error' => $s->error,
            'images' => array_map(fn (string $path): ?string => Files::url($path, 30), (array) ($s->images ?? [])),
        ];
    }

    private function classFacts()
    {
        return EnrichmentFact::query()->where('kind', FactKind::TryOn)->where('key', 'class')->where('status', FactStatus::Approved);
    }

    private function shopId(): ?string
    {
        return app(TenantContext::class)->id();
    }

    private function inShop(callable $callback): mixed
    {
        $shop = $this->shopId();

        return $shop === null ? null : app(TenantContext::class)->run($shop, $callback);
    }
}
