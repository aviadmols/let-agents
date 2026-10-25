<?php

namespace App\Modules\Demo\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Features\FeatureRegistry;
use App\Core\Settings\SettingRegistry;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Enums\ConnectionStatus;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Demo\Models\DemoSite;
use App\Modules\Tenancy\Contracts\CreatesShops;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The demo store: a shop of its own (status demo), so nothing of it ever touches a real store's data, and nothing
 * scheduled ever touches it (every schedule takes active shops). Connected to its public site (platform "demo",
 * PublicStoreFeed), answering on our own demo page (the app's host among its hosts). Held to what a demo needs and
 * may cost: so many products and pages, a month's budget that stops shoppers too, so many answers and photo
 * searches, a few store questions answered ahead, a cart that only shows itself, nothing a night or a team would do (enrichment, reviews,
 * matching, posting). What matters in its search, as they said: the pictures (photo search, alike by picture) or
 * the content (no pictures read), or both.
 */
final class BuildDemoStore
{
    /** Off in every demo store: work a night, a team or a live store does, never a demo. */
    private const OFF = [
        'enrichment.nightly', 'enrichment.weekly_audit', 'enrichment.auto_highlights', 'enrichment.search_summary',
        'enrichment.section_questions', 'enrichment.picture_suggestions', 'enrichment.web_lookup', 'enrichment.auto_review', 'enrichment.auto_publish_rules',
        'retrieval.ai_matching', 'catalog.sample_pages', 'catalog.page_choices', 'catalog.daily_sync', 'catalog.stock_checks', 'catalog.live_sync', 'catalog.refresh',
        'assistant.ask_review', 'assistant.team_drafts', 'assistant.search_whatsapp', 'assistant.on_products', 'assistant.on_content',
        'search.resolve_empty', 'search.ai_share', 'search.agent_connector', 'search.share_results', 'search.nudge', 'search.take_over_field',
        'improvement.daily_review', 'improvement.post_to_store', 'knowledge.promote', 'widget.on_products', 'widget.on_content', 'leads.enabled',
    ];

    public function __construct(
        private readonly CreatesShops $shops,
        private readonly TenantContext $tenant,
    ) {}

    public function handle(DemoSite $site): Shop
    {
        $matters = (string) ($site->choices['matters'] ?? 'both');
        $pictures = $site->readsPictures();

        $shop = DB::transaction(function () use ($site): Shop {
            $shop = $this->shops->handle([
                'name' => $site->title ?? $site->host,
                'domain' => $site->host,
                'platform' => 'woocommerce',
                'slug' => 'demo-'.Str::lower(Str::random(6)),
                'content_locale' => $site->choices['language'] ?? $site->locale,
                'status' => ShopStatus::Demo->value,
            ]);
            $shop->forceFill(['profile' => ['kind' => (string) ($site->choices['kind'] ?? 'store')]])->save();

            $this->tenant->runUnscoped(function () use ($shop, $site): void {
                $connection = new StoreConnection;
                $connection->forceFill([
                    'shop_id' => $shop->id,
                    'platform' => 'demo',
                    'access_token' => 'lat_'.Str::random(48),
                    'site_url' => 'https://'.$site->host,
                    // the drawer answers on our demo page, at our own host
                    'site_info' => ['hosts' => array_values(array_filter([parse_url((string) config('app.url'), PHP_URL_HOST)])), 'demo' => ['platform' => null]],
                ])->save();
                // the token's save leaves it untested; a demo's is connected
                $connection->forceFill(['status' => ConnectionStatus::Connected])->save();
            });

            return $shop;
        });

        $settings = [
            'catalog.max_products' => (int) Settings::get('demo.max_products'),
            'catalog.max_content_items' => (int) Settings::get('demo.max_content'),
            'ai.shop_monthly_cap_usd' => (float) Settings::get('demo.budget_usd'),
            'ai.shop_cap_stops_shoppers' => true,
            'assistant.answers_per_month' => (int) Settings::get('demo.questions'),
            'search.photos_per_month' => (int) Settings::get('demo.photos'),
            'assistant.prepared_per_sync' => (int) Settings::get('demo.prepared_questions'),
            'enrichment.max_branches' => (int) Settings::get('demo.vocabulary_branches'),
            // a demo's tags are its vocabulary's: none waits for a person (Vans: four held at 49 to 64 of 80)
            'enrichment.schema_pass_score' => (int) Settings::get('demo.vocabulary_pass_score'),
            // each product's main picture only: its other pictures (side views) were most of a demo's scan time
            'retrieval.gallery_pictures' => 0,
            'assistant.post_knowledge_posts' => 20,
            // the cart as a store has it: the drawer adds to no real cart in a demo (LetsSearchConfig.demo), only shows it
            'search.show_cart' => true,
            'search.similar_by' => ['look' => 'picture', 'content' => 'meaning'][$matters] ?? 'both',
            'search.photo_match' => $matters === 'content' ? 'function' : 'look',
        ];
        foreach ($settings as $key => $value) {
            if (app(SettingRegistry::class)->has($key)) {
                Settings::set($key, $value, $shop->id);
            }
        }

        foreach (self::OFF as $key) {
            if (app(FeatureRegistry::class)->has($key)) {
                Features::override($key, false, $shop->id);
            }
        }
        foreach (['search.photos', 'retrieval.image_index', 'retrieval.image_captions'] as $key) {
            if (app(FeatureRegistry::class)->has($key)) {
                Features::override($key, $pictures, $shop->id);
            }
        }
        // whoever tries a demo asks as much as they like (as Aviad asked, 2026-10-25): no visitor's own daily limit; the
        // demo's daily questions, its month's answers and its budget still hold
        if (app(FeatureRegistry::class)->has('assistant.visitor_limit')) {
            Features::override('assistant.visitor_limit', false, $shop->id);
        }
        // the wishlist a store would offer, a heart on each result: kept by us, and deleted with the demo's store
        if (app(FeatureRegistry::class)->has('shortlist.wishlist')) {
            Features::override('shortlist.wishlist', true, $shop->id);
        }

        $site->forceFill(['shop_id' => $shop->id, 'status' => DemoSite::SCANNING])->save();

        return $shop;
    }
}
