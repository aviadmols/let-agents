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
 * the page the manager names, or else the store's largest category, its whole catalogue and its
 * front page, read in turn until code finds the cards from the links to the store's own products.
 * Every page tried is in the run, with what it answered and how many product links it held. Only when code finds none, a model reads a short outline of the page and
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
     * @param  string|null  $page  a page of the store the manager names, read first
     * @return array{found: bool, selector?: string, cards?: int, page?: string, by?: string, reason?: string, tried: list<array{page: string, status: int|null, links: int}>}
     */
    public function handle(string $shopId, ?string $page = null): array
    {
        [$pages, $paths] = $this->tenant->run($shopId, fn (): array => [$this->pages($page), $this->paths()]);

        if ($pages === [] || $paths === []) {
            return ['found' => false, 'reason' => 'no_page', 'tried' => []];
        }

        $result = ['found' => false, 'reason' => 'not_found', 'tried' => []];
        $this->runs->track(
            agent: self::AGENT,
            action: self::ACTION,
            shopId: $shopId,
            trigger: RunTrigger::Manual,
            input: ['pages' => $pages],
            work: function (RunContext $run) use ($pages, $paths, &$result): void {
                $best = null;

                foreach ($pages as $url) {
                    [$status, $html] = $this->fetch($url);
                    $links = $html === null ? 0 : ThemeCards::linkCount($html, $paths);
                    $result['tried'][] = ['page' => $url, 'status' => $status, 'links' => $links];
                    $found = $html === null ? null : ThemeCards::find($html, $paths);

                    if ($found !== null) {
                        $result = ['found' => true, 'selector' => $found['selector'], 'cards' => $found['cards'], 'page' => $url, 'by' => 'code', 'tried' => $result['tried']];
                        $run->output(['tried' => $result['tried'], 'selector' => $found['selector'], 'cards' => $found['cards'], 'by' => 'code'])
                            ->summary('shortlist::runs.cards_found', ['selector' => $found['selector'], 'cards' => (string) $found['cards']]);

                        return;
                    }

                    if ($html !== null && $links >= ThemeCards::MIN_CARDS && ($best === null || $links > $best[1])) {
                        $best = [$url, $links, $html];
                    }
                }

                // Code found no cards on any page: a model reads the page with the most product links.
                $asked = $best === null ? null : $this->ask($run, $best[2], $paths);

                if ($asked !== null) {
                    $result = ['found' => true, 'selector' => $asked['selector'], 'cards' => $asked['cards'], 'page' => $best[0], 'by' => 'model', 'tried' => $result['tried']];

                    return;
                }

                $result['reason'] = $best === null ? 'no_links' : 'not_found';

                // A model that was asked said why in the run already.
                if ($best === null) {
                    $run->output(['tried' => $result['tried']])->summary('shortlist::runs.cards_none', ['pages' => (string) count($result['tried'])]);
                }
            },
        );

        return $result;
    }

    /**
     * The pages read in turn: the one named, the category with the most products, the whole
     * catalogue (Shopify's /collections/all, WooCommerce's /shop/), the front page. Inside the tenant.
     *
     * @return list<string>
     */
    private function pages(?string $named): array
    {
        $product = CatalogProduct::query()->active()->whereNotNull('url')->value('url');
        $host = is_string($product) ? strtolower((string) parse_url($product, PHP_URL_HOST)) : '';
        $pages = [];

        // A page the manager names is read only on the store's own address.
        $named = trim((string) $named);
        if (preg_match('#^https?://#i', $named) === 1 && strtolower((string) parse_url($named, PHP_URL_HOST)) === $host) {
            $pages[] = $named;
        }

        $category = CatalogCategory::query()->whereNull('removed_at')->whereNotNull('url')->orderByDesc('product_count')->value('url');
        if (is_string($category) && preg_match('#^https?://#i', $category) === 1) {
            $pages[] = $category;
        }

        if ($host !== '') {
            $pages[] = 'https://'.$host.(str_contains((string) $product, '/products/') ? '/collections/all' : '/shop/');
            $pages[] = 'https://'.$host.'/';
        }

        return array_values(array_unique($pages));
    }

    /** @return list<string> the store's product addresses. Inside the tenant. */
    private function paths(): array
    {
        // All of them: a page shows any 48 of tens of thousands, and a sample would miss most.
        return CatalogProduct::query()->active()->whereNotNull('url')->toBase()->pluck('url')->map(fn ($u): string => (string) $u)->all();
    }

    /** @return array{0: int|null, 1: string|null} what the page answered, and its HTML when it did */
    private function fetch(string $url): array
    {
        // Only the store's public pages, never an address inside our network.
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($host === '' || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [null, null];
        }

        try {
            $response = Http::timeout(20)->connectTimeout(5)->withHeaders(['Accept' => 'text/html', 'Accept-Language' => 'he,en;q=0.8', 'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36 LetAgents/1.0'])->get($url);
        } catch (Throwable) {
            return [null, null];
        }

        return [$response->status(), $response->successful() ? substr($response->body(), 0, 3_000_000) : null];
    }

    /**
     * A model names a selector from an outline of the page: for a few products, the elements
     * around their link, from the link up. Checked by code before it is kept.
     *
     * @param  list<string>  $paths
     * @return array{selector: string, cards: int}|null
     */
    private function ask(RunContext $run, string $html, array $paths): ?array
    {
        $outline = self::outline($html, $paths);

        if ($outline === []) {
            return null;
        }

        $kept = null;
        (function () use ($run, $html, $paths, $outline, &$kept): void {
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
        })();

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
