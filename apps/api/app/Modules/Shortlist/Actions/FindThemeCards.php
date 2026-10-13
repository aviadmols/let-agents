<?php

namespace App\Modules\Shortlist\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\AgentModel;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelCallFailed;
use App\Modules\Ai\Contracts\SpendCapReached;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Runs\Contracts\RecordsRuns;
use App\Modules\Runs\Contracts\RunContext;
use App\Modules\Runs\Enums\RunTrigger;
use App\Modules\Shortlist\Support\ThemeCards;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Where the store's theme draws its product cards, so the heart and the compare box go on them:
 * the store's largest category page is read, and code finds the cards from the links to the
 * store's own products. Only when code finds none, a model reads a short outline of the page and
 * names a selector; code then checks it on the page as it checks its own, and a selector that does
 * not name the cards is never offered.
 */
final class FindThemeCards
{
    public const AGENT = 'shortlist.card_finder';

    public const ACTION = 'shortlist.find_cards';

    private const PROMPT_VERSION = 1;

    /** Products whose ancestors the model is shown. */
    private const EXAMPLES = 6;

    private const LEVELS = 8;

    public function __construct(
        private readonly TenantContext $tenant,
        private readonly RecordsRuns $runs,
        private readonly ChatModel $models,
        private readonly SpendGuard $spend,
    ) {}

    /**
     * @return array{found: bool, selector?: string, cards?: int, page?: string, by?: string, reason?: string}
     */
    public function handle(string $shopId): array
    {
        [$page, $paths] = $this->tenant->run($shopId, fn (): array => [$this->page(), $this->paths()]);

        if ($page === null || $paths === []) {
            return ['found' => false, 'reason' => 'no_page'];
        }

        $html = $this->fetch($page);

        if ($html === null) {
            return ['found' => false, 'reason' => 'unreadable', 'page' => $page];
        }

        $found = ThemeCards::find($html, $paths);

        if ($found !== null) {
            return ['found' => true, 'selector' => $found['selector'], 'cards' => $found['cards'], 'page' => $page, 'by' => 'code'];
        }

        $asked = $this->ask($shopId, $html, $paths);

        return $asked === null
            ? ['found' => false, 'reason' => 'not_found', 'page' => $page]
            : ['found' => true, 'selector' => $asked['selector'], 'cards' => $asked['cards'], 'page' => $page, 'by' => 'model'];
    }

    /** The category with the most products that has an address, the store's front page otherwise. Inside the tenant. */
    private function page(): ?string
    {
        $url = CatalogCategory::query()->whereNull('removed_at')->whereNotNull('url')->orderByDesc('product_count')->value('url');

        if (is_string($url) && preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        $product = CatalogProduct::query()->active()->whereNotNull('url')->value('url');
        $host = is_string($product) ? parse_url($product, PHP_URL_HOST) : null;

        return is_string($host) ? 'https://'.$host.'/' : null;
    }

    /** @return list<string> the store's product addresses. Inside the tenant. */
    private function paths(): array
    {
        return CatalogProduct::query()->active()->whereNotNull('url')->limit(5000)->pluck('url')->map(fn ($u): string => (string) $u)->all();
    }

    private function fetch(string $url): ?string
    {
        // Only the store's public pages, never an address inside our network.
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        try {
            $response = Http::timeout(20)->connectTimeout(5)->withHeaders(['Accept' => 'text/html', 'User-Agent' => 'Mozilla/5.0 (compatible; LetAgents/1.0)'])->get($url);
        } catch (Throwable) {
            return null;
        }

        return $response->successful() ? mb_substr($response->body(), 0, 3_000_000) : null;
    }

    /**
     * A model names a selector from an outline of the page: for a few products, the elements
     * around their link, from the link up. Checked by code before it is kept.
     *
     * @param  list<string>  $paths
     * @return array{selector: string, cards: int}|null
     */
    private function ask(string $shopId, string $html, array $paths): ?array
    {
        $outline = self::outline($html, $paths);

        if ($outline === []) {
            return null;
        }

        $kept = null;
        $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: RunTrigger::Manual,
            input: ['products' => count($outline)],
            work: function (RunContext $run) use ($html, $paths, $outline, &$kept): void {
                $role = AgentModel::from('shortlist.card_finder_provider', 'shortlist.card_finder_model');
                $system = (string) file_get_contents(__DIR__.'/../Prompts/card_finder.v'.self::PROMPT_VERSION.'.md');
                $user = (string) json_encode(['products' => $outline], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $in = (float) Settings::get('shortlist.card_finder_input_usd_per_million');
                $out = (float) Settings::get('shortlist.card_finder_output_usd_per_million');
                $max = (int) Settings::get('shortlist.card_finder_max_output_tokens');

                try {
                    $this->spend->assertCanSpend(((mb_strlen($system) + mb_strlen($user)) / 2 * $in + $max * $out) / 1_000_000);
                    $reply = $this->models->json($role->provider, $role->model, $system, $user, $max);
                    $run->usage($role->provider->value, $role->model, $reply->inputTokens, $reply->outputTokens, 0, $reply->costUsd($in, $out));
                } catch (SpendCapReached $e) {
                    $run->fail('shortlist::runs.spend_cap', [], $e->getMessage());

                    return;
                } catch (ModelCallFailed $e) {
                    $run->fail('shortlist::runs.model_failed', ['reason' => $e->reason], $e->getMessage());

                    return;
                }

                // Code decides: the selector must name the cards, each linking to one product.
                $selector = trim((string) ($reply->data['selector'] ?? ''));
                $checked = $selector === '' ? null : ThemeCards::check($html, $selector, $paths);

                if ($checked === null) {
                    $run->output(['selector' => $selector, 'kept' => false])->summary('shortlist::runs.cards_refused', ['selector' => $selector === '' ? '—' : $selector]);

                    return;
                }

                $kept = ['selector' => $checked['selector'], 'cards' => $checked['cards']];
                $run->output(['selector' => $checked['selector'], 'cards' => $checked['cards'], 'kept' => true])->summary('shortlist::runs.cards_found', ['selector' => $checked['selector'], 'cards' => (string) $checked['cards']]);
            },
        );

        return $kept;
    }

    /**
     * For a few products, the chain of elements from the link up: tag and classes only.
     *
     * @param  list<string>  $paths
     * @return list<list<string>>
     */
    private static function outline(string $html, array $paths): array
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return [];
        }

        $known = array_flip(array_map([ThemeCards::class, 'path'], $paths));
        $seen = [];
        $out = [];

        foreach ((new DOMXPath($doc))->query('//body//a[@href]') ?: [] as $a) {
            $key = ThemeCards::path((string) $a->getAttribute('href'));

            if (! isset($known[$key]) || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $chain = [];
            $node = $a;
            for ($level = 0; $level < self::LEVELS && $node instanceof DOMElement && $node->nodeName !== 'body'; $level++) {
                $class = trim((string) preg_replace('/\s+/', ' ', (string) $node->getAttribute('class')));
                $chain[] = $node->nodeName.($class === '' ? '' : '.'.str_replace(' ', '.', mb_substr($class, 0, 160)));
                $node = $node->parentNode;
            }
            $out[] = $chain;

            if (count($out) >= self::EXAMPLES) {
                break;
            }
        }

        return $out;
    }
}
