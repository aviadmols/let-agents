<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Ai\Contracts\WebSearchModel;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Models\EnrichmentWebLookup;
use App\Modules\Enrichment\Scanning\Units;
use App\Modules\Enrichment\Scanning\WebEvidence;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A product's missing details, asked of the web by a model that searches (docs/strict-scan.md).
 *
 * The model is never believed on its word. For each detail it names sources, each with the sentence
 * it rests on; code then checks that the address is one the search returned, that the page really
 * holds the sentence word for word, and that the sentence says the value. A value is `certain` only
 * when two independent sites (not the store's own) or the manufacturer say it; one is `likely`; none
 * is `not_found`. A model of the other family then reads only the sentences and says whether they
 * are about this product and this kit, not a similar one.
 *
 * Nothing becomes a fact here. The lookup is kept for a person to approve (ApplyTeamValue). Only a
 * product with a strong identifier is asked about; the same question is not asked again for 90
 * days; every call asks SpendGuard first and is counted against the shop's checks for the month.
 * Inside the shop's tenant.
 */
final class LookUpOnTheWeb
{
    public const AGENT = 'enrichment.web_lookup';

    public const ACTION = 'enrichment.web_lookup';

    /** The released version each prompt is sent at; a released one is never edited. */
    private const PROMPTS = ['web_researcher' => 1, 'web_verifier' => 1];

    /** A question asked within this many days is answered from the kept lookup. */
    public const FRESH_DAYS = 90;

    private const RESEARCHER_OUTPUT_TOKENS = 3000;

    private const VERIFIER_OUTPUT_TOKENS = 1500;

    /** What a search adds to the input: results read by the model, before it answers. */
    private const SEARCH_INPUT_TOKENS = 25000;

    /** A model number in a title: "DTD153RME", "GA-5030", "BHR243". */
    private const MODEL_NUMBER = '/\b[A-Za-z]{1,5}[\s-]?\d{2,}[A-Za-z0-9-]*\b/u';

    public function __construct(
        private readonly RecordsRuns $runs,
        private readonly TenantContext $tenant,
        private readonly WebSearchModel $web,
        private readonly ChatModel $chat,
        private readonly SpendGuard $spend,
        private readonly ApplyTeamValue $apply,
    ) {}

    /**
     * @param  list<string>  $attributeKeys  the details to ask for, all in one lookup
     * @return array{lookup: EnrichmentWebLookup|null, problem: string|null, kept: bool} problem is a key of enrichment::missing.web_problems; kept: answered from an earlier lookup
     */
    public function handle(string $productId, array $attributeKeys, ?int $userId = null): array
    {
        $shopId = $this->tenant->require();
        $outcome = ['lookup' => null, 'problem' => null, 'kept' => false];

        if (! Features::enabled('enrichment.web_lookup', $shopId) || (int) Settings::get('enrichment.web_checks_per_month', $shopId) <= 0) {
            return ['problem' => 'off'] + $outcome;
        }

        $product = CatalogProduct::query()->whereNull('removed_at')->find($productId);
        $vocabularyId = $product === null ? null : EnrichmentFact::query()->where('product_id', $productId)->where('kind', FactKind::Type)
            ->where('status', FactStatus::Approved)->orderBy('id')->value('vocabulary_id');

        if ($product === null || $vocabularyId === null) {
            return ['problem' => 'unknown_product'] + $outcome;
        }

        $definition = EnrichmentVocabulary::query()->find($vocabularyId)->definition();
        $locale = app()->getLocale() === 'en' ? 'en' : 'he';
        $details = [];
        foreach (array_values(array_unique($attributeKeys)) as $key) {
            $entry = $definition->attribute($key);
            if ($entry !== null && in_array($entry['type'], ['enum', 'number'], true)) {
                $details[$key] = $this->detail($definition->isMultiple($key), $entry, $locale);
            }
        }

        if ($details === []) {
            return ['problem' => 'nothing_to_ask'] + $outcome;
        }

        $identity = $this->identity($product);

        if ($identity === null) {
            return ['problem' => 'no_identifier'] + $outcome;
        }

        $fingerprint = hash('sha256', implode('|', [$product->id, $identity['sku'], $identity['gtin'], $identity['brand'], implode(',', array_keys($details)), json_encode(self::PROMPTS)]));
        $kept = EnrichmentWebLookup::query()->where('product_id', $product->id)->where('fingerprint', $fingerprint)->where('created_at', '>=', now()->subDays(self::FRESH_DAYS))->latest()->first();

        if ($kept !== null) {
            return ['lookup' => $kept, 'kept' => true] + $outcome;
        }

        if (EnrichmentWebLookup::query()->where('created_at', '>=', now()->startOfMonth())->count() >= (int) Settings::get('enrichment.web_checks_per_month', $shopId)) {
            return ['problem' => 'monthly_limit'] + $outcome;
        }

        $researcher = AgentModel::from('enrichment.web_researcher_provider', 'enrichment.web_researcher_model');
        $verifier = AgentModel::from('enrichment.web_verifier_provider', 'enrichment.web_verifier_model');

        // What one family found, the other checks.
        if ($researcher->provider->family() === $verifier->provider->family()) {
            return ['problem' => 'same_family'] + $outcome;
        }

        $input = [
            'product' => ['title' => $product->title, 'brand' => $identity['brand'], 'sku' => $identity['sku'], 'gtin' => $identity['gtin'], 'category' => (string) (($product->categoryPaths()[0] ?? [])[0] ?? ''), 'store' => (string) Shop::query()->whereKey($shopId)->value('domain')],
            'details' => array_values($details),
        ];
        $searches = (int) Settings::get('enrichment.web_max_searches');

        try {
            $this->spend->assertCanSpend(
                (self::SEARCH_INPUT_TOKENS * $this->price('researcher', 'input') + self::RESEARCHER_OUTPUT_TOKENS * $this->price('researcher', 'output')) / 1_000_000
                + $searches * (float) Settings::get('enrichment.web_search_usd_per_search')
                + (3000 * $this->price('verifier', 'input') + self::VERIFIER_OUTPUT_TOKENS * $this->price('verifier', 'output')) / 1_000_000,
            );
        } catch (SpendCapReached) {
            return ['problem' => 'spend_cap'] + $outcome;
        }

        $failed = null;
        $lookup = null;

        $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            work: function (RunContext $run) use ($product, $vocabularyId, $details, $input, $researcher, $verifier, $searches, $fingerprint, $userId, $definition, &$lookup, &$failed): void {
                try {
                    $answer = $this->web->research($researcher->provider, $researcher->model, $this->prompt('web_researcher'), (string) json_encode($input, JSON_UNESCAPED_UNICODE), self::RESEARCHER_OUTPUT_TOKENS, $searches);
                } catch (ModelCallFailed $e) {
                    $failed = $e->reason === ModelCallFailed::UNSUPPORTED ? 'cannot_search' : 'model_failed';
                    $run->fail('assistant::runs.model_failed', ['reason' => $e->reason], $e->reason);

                    return;
                }

                $cost = $answer->reply->costUsd($this->price('researcher', 'input'), $this->price('researcher', 'output')) + $answer->searches * (float) Settings::get('enrichment.web_search_usd_per_search');
                $run->usage($researcher->provider->value, $researcher->model, $answer->reply->inputTokens, $answer->reply->outputTokens, 0, $cost);

                $results = $this->evaluate($details, $answer->reply->data, $answer->urls, $definition, $vocabularyId, $input['product']['store']);
                [$results, $verdicts, $verifierCost] = $this->verify($run, $verifier, $input['product'], $results);

                $best = $this->best($results);
                $lookup = EnrichmentWebLookup::query()->create([
                    'shop_id' => $product->shop_id,
                    'product_id' => $product->id,
                    'vocabulary_id' => $vocabularyId,
                    'attribute_keys' => array_keys($details),
                    'fingerprint' => $fingerprint,
                    'certainty' => $best,
                    'results' => $results,
                    'verifier' => $verdicts === [] ? null : ['model' => $verifier->model, 'claims' => $verdicts],
                    'requested_by' => $userId,
                    'run_id' => $run->runId,
                    'cost_usd' => round($cost + $verifierCost, 6),
                ]);

                $run->output(['lookup' => $lookup->id, 'certainty' => $best, 'details' => array_map(fn (array $r): string => $r['certainty'], $results), 'searches' => $answer->searches])
                    ->summary('enrichment::runs.web_lookup', ['product' => mb_substr($product->title, 0, 60), 'certainty' => $best]);
            },
        );

        return ['lookup' => $lookup, 'problem' => $failed, 'kept' => false];
    }

    /**
     * The answer of a lookup approved, edited or turned down by a person. Approved, it becomes the
     * team's value with the sources it rested on; turned down, it is kept so it is not asked again.
     *
     * @return array{ok: bool, problem: string|null}
     */
    public function decide(EnrichmentWebLookup $lookup, string $attribute, bool $approve, string|int|float|array|null $value = null): array
    {
        $result = $lookup->results[$attribute] ?? null;

        if ($result === null || ($result['decision'] ?? null) !== EnrichmentWebLookup::PENDING) {
            return ['ok' => false, 'problem' => 'unknown_lookup'];
        }

        $done = ['ok' => true, 'problem' => null];

        if ($approve) {
            $value ??= $result['value'];
            $done = $this->apply->set($lookup->product_id, (string) $lookup->vocabulary_id, $attribute, $value, [
                'web' => array_values(array_map(fn (array $s): array => ['url' => $s['url'], 'quote' => $s['quote']], array_filter((array) $result['sources'], fn (array $s): bool => (bool) $s['verified']))),
                'certainty' => $result['certainty'],
            ]);

            if (! $done['ok']) {
                return $done;
            }
        }

        $results = $lookup->results;
        $results[$attribute]['decision'] = $approve ? EnrichmentWebLookup::APPROVED : EnrichmentWebLookup::REJECTED;
        $results[$attribute]['decided_at'] = now()->toIso8601String();
        $lookup->update(['results' => $results]);

        return $done;
    }

    /**
     * @param  array<string, array<string, mixed>>  $details
     * @param  array<string, mixed>  $data  the researcher's JSON
     * @param  list<string>  $urls  what the search returned
     * @return array<string, array<string, mixed>> attribute => the evaluated answer
     */
    private function evaluate(array $details, array $data, array $urls, VocabularyDefinition $definition, string $vocabularyId, string $storeDomain): array
    {
        $known = array_map(fn (string $u): string => rtrim($u, '/'), $urls);
        $store = $storeDomain === '' ? '' : WebEvidence::site($storeDomain);
        $manufacturers = (string) Settings::get('enrichment.web_manufacturer_domains', $this->tenant->require());
        $answers = [];
        foreach ((array) ($data['answers'] ?? []) as $answer) {
            if (is_array($answer) && is_string($answer['key'] ?? null)) {
                $answers[$answer['key']] = $answer;
            }
        }

        $pages = [];
        $results = [];

        foreach ($details as $key => $detail) {
            $answer = $answers[$key] ?? [];
            $value = $answer['value'] ?? null;
            $checked = $value === null || $value === '' ? null : $this->apply->check($vocabularyId, $key, $value);
            $result = ['value' => $checked !== null && $checked['ok'] ? $value : null, 'unit' => $detail['unit'] ?? null, 'certainty' => EnrichmentWebLookup::NOT_FOUND, 'why' => '', 'conflict' => (bool) ($answer['conflict'] ?? false), 'sources' => [], 'decision' => EnrichmentWebLookup::PENDING];

            if ($checked === null) {
                $result['why'] = 'no_value';
            } elseif (! $checked['ok']) {
                $result['why'] = 'invalid_value';
            } else {
                $entry = $definition->attribute($key);
                $options = array_values(array_filter((array) ($entry['values'] ?? []), fn (array $o): bool => in_array($o['key'], array_map('strval', (array) $value), true)));

                foreach (array_slice((array) ($answer['sources'] ?? []), 0, 3) as $source) {
                    $url = (string) ($source['url'] ?? '');
                    $quote = trim((string) ($source['quote'] ?? ''));
                    $host = WebEvidence::host($url);
                    $row = ['url' => $url, 'host' => $host, 'quote' => mb_substr($quote, 0, 400), 'same_product' => ($source['same_product'] ?? false) === true, 'verified' => false, 'independent' => false, 'manufacturer' => false, 'why' => ''];

                    if ($host === null) {
                        $row['why'] = 'bad_address';
                    } elseif (! in_array(rtrim($url, '/'), $known, true)) {
                        $row['why'] = 'not_in_results';
                    } else {
                        $page = $pages[$url] ??= $this->page($url);
                        $says = $entry['type'] === 'number'
                            ? WebEvidence::says($quote, (float) $checked['rows'][0]['value_number'])
                            : array_reduce($options, fn (bool $all, array $o): bool => $all && WebEvidence::names($quote, $o), $options !== []);
                        $row['why'] = $page === null ? 'page_unreadable' : (! WebEvidence::quoteOn($page, $quote) ? 'quote_not_on_page' : (! $says ? 'quote_does_not_say_it' : ''));
                        $row['verified'] = $row['why'] === '' && $row['same_product'];
                        $row['independent'] = $row['verified'] && ($store === '' || WebEvidence::site($host) !== $store);
                        $row['manufacturer'] = $row['independent'] && $manufacturers !== '' && WebEvidence::inList($host, $manufacturers);

                        if ($row['why'] === '' && ! $row['same_product']) {
                            $row['why'] = 'not_same_product';
                        }
                    }

                    $result['sources'][] = $row;
                }

                $sites = array_unique(array_map(fn (array $s): string => WebEvidence::site((string) $s['host']), array_filter($result['sources'], fn (array $s): bool => $s['independent'])));
                $manufacturer = array_filter($result['sources'], fn (array $s): bool => $s['manufacturer']) !== [];
                $result['certainty'] = count($sites) >= 2 || $manufacturer ? EnrichmentWebLookup::CERTAIN : (count($sites) === 1 ? EnrichmentWebLookup::LIKELY : EnrichmentWebLookup::NOT_FOUND);
                $result['why'] = $result['certainty'] === EnrichmentWebLookup::NOT_FOUND ? 'no_confirmed_source' : '';

                if ($result['conflict'] && $result['certainty'] !== EnrichmentWebLookup::NOT_FOUND) {
                    $result['certainty'] = EnrichmentWebLookup::CONFLICTING;
                }
            }

            $results[$key] = $result;
        }

        return $results;
    }

    /**
     * The other family reads only the confirmed sentences and says whether they are about this
     * product and this kit. What it refuses is not found.
     *
     * @param  array<string, mixed>  $product
     * @param  array<string, array<string, mixed>>  $results
     * @return array{0: array<string, array<string, mixed>>, 1: list<array<string, mixed>>, 2: float}
     */
    private function verify(RunContext $run, AgentModel $verifier, array $product, array $results): array
    {
        $claims = [];
        foreach ($results as $key => $result) {
            if (in_array($result['certainty'], [EnrichmentWebLookup::CERTAIN, EnrichmentWebLookup::LIKELY, EnrichmentWebLookup::CONFLICTING], true)) {
                $claims[] = ['key' => $key, 'value' => $result['value'], 'sources' => array_values(array_map(fn (array $s): array => ['host' => $s['host'], 'quote' => $s['quote']], array_filter($result['sources'], fn (array $s): bool => $s['verified'])))];
            }
        }

        if ($claims === []) {
            return [$results, [], 0.0];
        }

        try {
            $reply = $this->chat->json($verifier->provider, $verifier->model, $this->prompt('web_verifier'), (string) json_encode(['product' => array_intersect_key($product, array_flip(['title', 'brand', 'sku', 'gtin'])), 'claims' => $claims], JSON_UNESCAPED_UNICODE), self::VERIFIER_OUTPUT_TOKENS, 'low');
        } catch (Throwable $e) {
            // Without the check nothing is offered as certain: the claims stay for a person, as likely.
            foreach ($claims as $claim) {
                $results[$claim['key']]['certainty'] = $results[$claim['key']]['certainty'] === EnrichmentWebLookup::CERTAIN ? EnrichmentWebLookup::LIKELY : $results[$claim['key']]['certainty'];
                $results[$claim['key']]['why'] = 'not_checked';
            }

            return [$results, [], 0.0];
        }

        $cost = $reply->costUsd($this->price('verifier', 'input'), $this->price('verifier', 'output'));
        $run->usage($verifier->provider->value, $verifier->model, $reply->inputTokens, $reply->outputTokens, 0, $cost);
        $verdicts = [];

        foreach ((array) ($reply->data['claims'] ?? []) as $verdict) {
            if (! is_array($verdict) || ! isset($results[$verdict['key'] ?? ''])) {
                continue;
            }

            $verdicts[] = ['key' => (string) $verdict['key'], 'same_product' => ($verdict['same_product'] ?? false) === true, 'why' => mb_substr((string) ($verdict['why'] ?? ''), 0, 200)];
        }

        $said = array_column($verdicts, 'same_product', 'key');
        foreach ($claims as $claim) {
            // A claim the verifier did not answer for is not confirmed.
            if (($said[$claim['key']] ?? false) !== true) {
                $results[$claim['key']]['certainty'] = EnrichmentWebLookup::NOT_FOUND;
                $results[$claim['key']]['why'] = 'other_product';
            }
        }

        return [$results, $verdicts, $cost];
    }

    /** @param array<string, array<string, mixed>> $results */
    private function best(array $results): string
    {
        $rank = [EnrichmentWebLookup::CERTAIN => 3, EnrichmentWebLookup::LIKELY => 2, EnrichmentWebLookup::CONFLICTING => 1, EnrichmentWebLookup::NOT_FOUND => 0];
        $best = EnrichmentWebLookup::NOT_FOUND;

        foreach ($results as $result) {
            if ($rank[$result['certainty']] > $rank[$best]) {
                $best = $result['certainty'];
            }
        }

        return $best;
    }

    /** @return array{sku: string, gtin: string, brand: string}|null null when nothing ties the product to one exact model */
    private function identity(CatalogProduct $product): ?array
    {
        $payload = $product->payload;
        $gtin = trim((string) ($payload['gtin'] ?? $payload['barcode'] ?? $payload['meta']['_global_unique_id'] ?? $payload['meta']['_gtin'] ?? ''));
        $brand = trim((string) ($product->brand ?: EnrichmentFact::query()->where('product_id', $product->id)->where('kind', FactKind::Brand)->where('status', FactStatus::Approved)->value('value_text')));
        $sku = trim((string) $product->sku);

        if ($gtin !== '') {
            return ['sku' => $sku, 'gtin' => $gtin, 'brand' => $brand];
        }

        return $brand !== '' && ($sku !== '' || preg_match(self::MODEL_NUMBER, $product->title) === 1) ? ['sku' => $sku, 'gtin' => '', 'brand' => $brand] : null;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function detail(bool $multiple, array $entry, string $locale): array
    {
        if ($entry['type'] === 'enum') {
            return ['key' => $entry['key'], 'label' => (string) ($entry['label'][$locale] ?? $entry['key']), 'type' => 'choice', 'multiple' => $multiple,
                'options' => array_values(array_map(fn (array $o): array => ['key' => $o['key'], 'label' => (string) ($o['label'][$locale] ?? $o['key'])], (array) ($entry['values'] ?? [])))];
        }

        return ['key' => $entry['key'], 'label' => (string) ($entry['label'][$locale] ?? $entry['key']), 'type' => 'number', 'unit' => (string) (Units::CANONICAL[$entry['dimension']] ?? ''), 'min' => $entry['min'], 'max' => $entry['max']];
    }

    /** The page's text, or null when it cannot be read. */
    private function page(string $url): ?string
    {
        try {
            $response = Http::timeout(12)->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; LetAgentsCheck/1.0)', 'Accept-Language' => 'he,en;q=0.8'])->get($url);
        } catch (Throwable) {
            return null;
        }

        return $response->successful() ? WebEvidence::plain($response->body()) : null;
    }

    private function prompt(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/../Prompts/'.$name.'.v'.self::PROMPTS[$name].'.md');
    }

    private function price(string $role, string $side): float
    {
        return (float) Settings::get("enrichment.web_{$role}_{$side}_usd_per_million");
    }
}
