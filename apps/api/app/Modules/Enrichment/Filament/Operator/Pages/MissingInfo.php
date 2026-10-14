<?php

namespace App\Modules\Enrichment\Filament\Operator\Pages;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\NeedsShopContext;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Enrichment\Actions\ApplyTeamValue;
use App\Modules\Enrichment\Actions\LookUpOnTheWeb;
use App\Modules\Enrichment\Actions\SuggestFromPicture;
use App\Modules\Enrichment\Jobs\LookUpOnTheWebJob;
use App\Modules\Enrichment\Jobs\SuggestFromPictureJob;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Models\EnrichmentWebLookup;
use App\Modules\Enrichment\Scanning\RequiredGaps;
use App\Modules\Enrichment\Scanning\Units;
use App\Modules\Enrichment\Support\MissingInfoCsv;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

/**
 * What a shopper needs to know before buying and the product does not say, by kind of product and
 * detail, for the team to fill in: one product, a few at once, or a spreadsheet. "Does not apply" takes
 * a detail off the list. What the team says is kept here, stands against later readings, and is
 * never written back to the store. Words for a shop manager: what is missing and where, nothing of
 * how the system works. The same screen serves the operator (any shop) and the manager (their own).
 */
class MissingInfo extends Page
{
    use NeedsShopContext;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = 'scan';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'enrichment/missing-info';

    protected string $view = 'enrichment::operator.missing-info';

    /** The kind and detail whose products are open: "type|attribute". */
    public ?string $openGroup = null;

    /** @var array<string, string|list<string>> product id => what was typed or chosen */
    public array $entries = [];

    /** @var list<string> product ids ticked for one value at once */
    public array $picked = [];

    /** @var string|list<string> the value for every ticked product */
    public string|array $bulkValue = '';

    /** A spreadsheet pasted back. */
    public string $csv = '';

    public static function getNavigationLabel(): string
    {
        return __('enrichment::missing.title');
    }

    public function getTitle(): string
    {
        return __('enrichment::missing.title');
    }

    public function getSubheading(): ?string
    {
        return __('enrichment::missing.help');
    }

    /** @return list<array<string, mixed>> */
    public function gaps(): array
    {
        $shop = app(TenantContext::class)->id();

        return $shop === null ? [] : app(TenantContext::class)->run($shop, fn (): array => RequiredGaps::of(
            [VocabularyDefinition::REQUIRED, VocabularyDefinition::RECOMMENDED],
            app()->getLocale() === 'en' ? 'en' : 'he',
        ));
    }

    /**
     * How many products have every detail the branches ask for, of all those that are asked about.
     *
     * @return array{missing: int, groups: int}
     */
    public function totals(): array
    {
        $gaps = $this->gaps();
        $products = [];
        foreach ($gaps as $gap) {
            foreach ($gap['missing'] as $product) {
                $products[$product['id']] = true;
            }
        }

        return ['missing' => count($products), 'groups' => count($gaps)];
    }

    /**
     * The input for one group: the values a choice may take, or the unit and range of a number.
     *
     * @param  array<string, mixed>  $gap
     * @return array{type: string, options: array<string, string>, multiple: bool, unit: string, min: float, max: float}
     */
    public function field(array $gap): array
    {
        $definition = EnrichmentVocabulary::query()->find($gap['vocabulary_id'])?->definition();
        $entry = $definition?->attribute($gap['attribute']) ?? ['type' => 'number'];
        $locale = app()->getLocale() === 'en' ? 'en' : 'he';

        if (($entry['type'] ?? 'number') === 'enum') {
            $options = [];
            foreach ((array) ($entry['values'] ?? []) as $option) {
                $options[(string) $option['key']] = (string) ($option['label'][$locale] ?? $option['key']);
            }

            return ['type' => 'enum', 'options' => $options, 'multiple' => $definition->isMultiple($gap['attribute']), 'unit' => '', 'min' => 0, 'max' => 0];
        }

        return ['type' => 'number', 'options' => [], 'multiple' => false, 'unit' => (string) (Units::CANONICAL[$entry['dimension'] ?? ''] ?? ''), 'min' => (float) ($entry['min'] ?? 0), 'max' => (float) ($entry['max'] ?? 0)];
    }

    public function toggleGroup(string $group): void
    {
        $this->openGroup = $this->openGroup === $group ? null : $group;
        $this->entries = [];
        $this->picked = [];
        $this->bulkValue = '';
    }

    /** One product's value. */
    public function saveOne(string $productId): void
    {
        $gap = $this->openGap();
        $value = $this->entries[$productId] ?? '';

        if ($gap === null || $value === '' || $value === []) {
            return;
        }

        $this->report($this->apply($gap, [$productId], $value));
    }

    /** One value for every ticked product. */
    public function saveMany(): void
    {
        $gap = $this->openGap();

        if ($gap === null || $this->picked === [] || $this->bulkValue === '' || $this->bulkValue === []) {
            return;
        }

        $this->report($this->apply($gap, $this->picked, $this->bulkValue));
        $this->picked = [];
        $this->bulkValue = '';
    }

    /** This detail does not apply to the product (or to every ticked one). */
    public function markNotApplicable(?string $productId = null): void
    {
        $gap = $this->openGap();
        $ids = $productId !== null ? [$productId] : $this->picked;

        if ($gap === null || $ids === []) {
            return;
        }

        app(TenantContext::class)->run((string) app(TenantContext::class)->id(), function () use ($gap, $ids): void {
            foreach ($ids as $id) {
                app(ApplyTeamValue::class)->notApplicable($id, (string) $gap['vocabulary_id'], (string) $gap['attribute']);
            }
        });
        $this->picked = [];
        Notification::make()->success()->title(__('enrichment::missing.not_applicable_done', ['count' => (string) count($ids)]))->send();
    }

    public function downloadCsv(): StreamedResponse
    {
        $csv = MissingInfoCsv::export($this->gaps());

        return response()->streamDownload(fn () => print ($csv), 'missing-info.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function importCsv(): void
    {
        if (trim($this->csv) === '') {
            return;
        }

        $shop = (string) app(TenantContext::class)->id();
        $result = app(TenantContext::class)->run($shop, fn (): array => MissingInfoCsv::import($this->csv, app(ApplyTeamValue::class)));
        $this->csv = '';

        $note = __('enrichment::missing.imported', ['applied' => (string) ($result['applied'] + $result['not_applicable']), 'problems' => (string) count($result['problems'])]);
        $first = $result['problems'][0] ?? null;
        $body = $first === null ? null : __('enrichment::missing.import_problem', ['line' => (string) $first['line'], 'problem' => __('enrichment::missing.problems.'.$first['problem'])]);

        Notification::make()->{$result['problems'] === [] ? 'success' : 'warning'}()->title($note)->body($body)->send();
    }

    /**
     * Whether a group's detail can be suggested from the products' pictures: a choice about how
     * the product looks, never a number or a yes/no.
     *
     * @param  array<string, mixed>  $gap
     */
    public function pictureOn(array $gap): bool
    {
        $shop = app(TenantContext::class)->id();

        if ($shop === null || ! Features::enabled('enrichment.picture_suggestions', $shop)) {
            return false;
        }

        $definition = EnrichmentVocabulary::query()->find($gap['vocabulary_id'])?->definition();

        return $definition !== null && ($definition->attribute($gap['attribute'])['type'] ?? '') === 'enum' && $definition->readFromImage($gap['attribute']);
    }

    /** The ticked products, or every shown one without a suggestion, looked at in their pictures, in the background. */
    public function suggestFromPicture(): void
    {
        $gap = $this->openGap();
        $shop = app(TenantContext::class)->id();

        if ($gap === null || $shop === null || ! $this->pictureOn($gap)) {
            return;
        }

        $ids = $this->picked !== [] ? $this->picked : array_column(array_filter($gap['missing'], fn (array $p): bool => $p['waiting'] === null), 'id');
        $ids = array_slice(array_values($ids), 0, SuggestFromPicture::MAX);

        if ($ids === []) {
            return;
        }

        SuggestFromPictureJob::dispatch($shop, (string) $gap['vocabulary_id'], (string) $gap['attribute'], $ids);
        $this->picked = [];
        Notification::make()->success()->title(__('enrichment::missing.picture_started', ['count' => (string) count($ids)]))->send();
    }

    /** A suggestion waiting for a person, approved as it is. */
    public function acceptWaiting(string $productId): void
    {
        $gap = $this->openGap();
        $product = $gap === null ? null : collect($gap['missing'])->firstWhere('id', $productId);

        if ($product === null || ($product['waiting_keys'] ?? []) === []) {
            return;
        }

        $value = $this->field($gap)['multiple'] ? $product['waiting_keys'] : $product['waiting_keys'][0];
        $this->report($this->apply($gap, [$productId], $value));
    }

    /** Whether the shop lets its team check a missing detail on the web. */
    public function webOn(): bool
    {
        $shop = app(TenantContext::class)->id();

        return $shop !== null && Features::enabled('enrichment.web_lookup', $shop) && (int) Settings::get('enrichment.web_checks_per_month', $shop) > 0;
    }

    /** The operator sees what each check cost and the log; a shop manager does not. */
    public function isOperator(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->is_operator;
    }

    /** Every missing detail of one product, asked of the web in one check, in the background. */
    public function lookUp(string $productId): void
    {
        $this->lookUpProducts([$productId]);
    }

    /** The ticked products, each in a check of its own. */
    public function lookUpPicked(): void
    {
        $this->lookUpProducts($this->picked);
        $this->picked = [];
    }

    /**
     * The latest check of each product of a group, for its detail: what was found, how sure, and
     * the sources with the sentence each rests on.
     *
     * @param  array<string, mixed>  $gap
     * @return array<string, array{lookup: string, result: array<string, mixed>, cost: string, label: string}>
     */
    public function webResults(array $gap): array
    {
        $shop = app(TenantContext::class)->id();

        if ($shop === null) {
            return [];
        }

        $field = $this->field($gap);

        return app(TenantContext::class)->run($shop, function () use ($gap, $field): array {
            $out = [];
            $lookups = EnrichmentWebLookup::query()->whereIn('product_id', array_column($gap['missing'], 'id'))->latest()->get();

            foreach ($lookups as $lookup) {
                if (isset($out[$lookup->product_id]) || ! isset($lookup->results[$gap['attribute']])) {
                    continue;
                }

                $result = $lookup->results[$gap['attribute']];
                $label = $field['type'] === 'enum'
                    ? implode(', ', array_map(fn ($key): string => $field['options'][(string) $key] ?? (string) $key, (array) ($result['value'] ?? [])))
                    : trim((string) ($result['value'] ?? '').' '.$field['unit']);
                $out[$lookup->product_id] = ['lookup' => $lookup->id, 'result' => $result, 'cost' => (string) $lookup->cost_usd, 'label' => $label];
            }

            return $out;
        });
    }

    /** A person approves (or turns down) what a web check found. */
    public function decideWeb(string $lookupId, string $attribute, bool $approve): void
    {
        $shop = (string) app(TenantContext::class)->id();
        $done = app(TenantContext::class)->run($shop, function () use ($lookupId, $attribute, $approve): array {
            $lookup = EnrichmentWebLookup::query()->find($lookupId);

            return $lookup === null ? ['ok' => false, 'problem' => 'unknown_lookup'] : app(LookUpOnTheWeb::class)->decide($lookup, $attribute, $approve);
        });

        $done['ok']
            ? Notification::make()->success()->title(__('enrichment::missing.'.($approve ? 'web_approved' : 'web_rejected')))->send()
            : Notification::make()->warning()->title(__('enrichment::missing.web_problems.'.$done['problem']))->send();
    }

    /**
     * The shop's latest web checks, with what each cost: the operator's view.
     *
     * @return list<array{product: string, certainty: string, details: string, cost: string, at: string}>
     */
    public function webLog(): array
    {
        $shop = app(TenantContext::class)->id();

        if ($shop === null || ! $this->isOperator()) {
            return [];
        }

        return app(TenantContext::class)->run($shop, fn (): array => EnrichmentWebLookup::query()->with('product:id,title')->latest()->limit(20)->get()
            ->map(fn (EnrichmentWebLookup $l): array => [
                'product' => (string) ($l->product?->title ?? ''),
                'certainty' => $l->certainty,
                'details' => implode(', ', array_map(fn (string $k, array $r): string => $k.': '.$r['certainty'].' / '.($r['decision'] ?? ''), array_keys($l->results), $l->results)),
                'cost' => number_format((float) $l->cost_usd, 4),
                'at' => (string) $l->created_at?->format('d/m H:i'),
            ])->all());
    }

    /** @param  list<string>  $productIds */
    private function lookUpProducts(array $productIds): void
    {
        $shop = app(TenantContext::class)->id();

        if ($shop === null || $productIds === [] || ! $this->webOn()) {
            return;
        }

        // A product's every missing detail, in one check.
        $details = [];
        foreach ($this->gaps() as $gap) {
            foreach ($gap['missing'] as $product) {
                if (in_array($product['id'], $productIds, true)) {
                    $details[$product['id']][] = (string) $gap['attribute'];
                }
            }
        }

        foreach ($details as $productId => $keys) {
            LookUpOnTheWebJob::dispatch($shop, (string) $productId, array_values(array_unique($keys)), Auth::id());
        }

        Notification::make()->success()->title(__('enrichment::missing.web_started', ['count' => (string) count($details)]))->send();
    }

    /** @return array<string, mixed>|null */
    private function openGap(): ?array
    {
        if ($this->openGroup === null) {
            return null;
        }

        foreach ($this->gaps() as $gap) {
            if ($this->openGroup === $gap['type'].'|'.$gap['attribute']) {
                return $gap;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $gap
     * @param  list<string>  $ids
     * @return array{done: int, problem: string|null}
     */
    private function apply(array $gap, array $ids, string|array $value): array
    {
        $done = 0;
        $problem = null;

        app(TenantContext::class)->run((string) app(TenantContext::class)->id(), function () use ($gap, $ids, $value, &$done, &$problem): void {
            foreach ($ids as $id) {
                $result = app(ApplyTeamValue::class)->set($id, (string) $gap['vocabulary_id'], (string) $gap['attribute'], $value);
                $result['ok'] ? $done++ : $problem ??= $result['problem'];
            }
        });

        return ['done' => $done, 'problem' => $problem];
    }

    /** @param  array{done: int, problem: string|null}  $result */
    private function report(array $result): void
    {
        if ($result['problem'] !== null) {
            Notification::make()->warning()->title(__('enrichment::missing.problems.'.$result['problem']))->send();

            return;
        }

        $this->entries = [];
        Notification::make()->success()->title(__('enrichment::missing.saved', ['count' => (string) $result['done']]))->send();
    }
}
