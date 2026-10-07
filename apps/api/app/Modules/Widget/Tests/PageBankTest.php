<?php

namespace App\Modules\Widget\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Analytics\Models\AnalyticsPopularity;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentContentProduct;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Models\EnrichmentRanking;
use App\Modules\Enrichment\Models\EnrichmentVocabulary;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Widget\Actions\BuildPageBank;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class PageBankTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private const TOKEN = 'rgt_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private string $site;

    private EnrichmentVocabulary $vocabulary;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $this->shop->id, 'site_url' => 'https://store.test', 'access_token' => self::TOKEN,
        ]));
        $this->site = SiteKeys::site(self::TOKEN);
        $this->vocabulary = $this->powerToolsVocabulary();

        $tools = $this->category('1751', 'כלי עבודה');
        $jigsaw = $this->product('10', 'מסור אנכי נטען 18V', 'x', [$tools], [
            'url' => 'https://store.test/product/jigsaw/',
            'payload' => ['relations' => [
                ['type' => 'cross_sell', 'target' => '12'],
                ['type' => 'cross_sell', 'target' => '11'],
                ['type' => 'upsell', 'target' => '13'],
                ['type' => 'cross_sell', 'target' => '14'],
            ]],
        ]);
        $this->product('11', 'סוללה 5Ah', 'x', [$tools], ['image_url' => 'https://store.test/battery.jpg']);
        $this->product('12', 'קרש אורן 20X45', 'x', [$tools], ['payload' => [
            'attributes' => [['name' => 'אורך', 'values' => ['3 מטר', '3.30 מטר'], 'used_for_variations' => true]],
            'meta' => ['price_text' => 'מחיר למטר'],
        ]]);
        $this->product('13', 'מסור אנכי מקצועי', 'x', [$tools]);
        $this->product('14', 'מטען', 'x', [$tools], ['in_stock' => false]);

        $this->fact($jigsaw, 'type', 'type', text: 'jigsaw');
        $this->fact($jigsaw, 'spec', 'weight_kg', number: 1.6, unit: 'kg');
        $this->fact($jigsaw, 'choice', 'power_source', text: 'cordless');
        $this->fact($jigsaw, 'flag', 'brushless', text: 'true');
        $this->fact($jigsaw, 'spec', 'voltage_v', number: 18, unit: 'V', status: 'needs_person');

        $this->ranking($jigsaw, 'weight_kg', 'min', 1.6, 'kg', ['type' => 'jigsaw', 'power_source' => 'cordless'], 9);
        $this->ranking($jigsaw, 'price', 'min', 499, null, ['type' => 'jigsaw', 'power_source' => 'cordless', 'kit' => 'body_only'], 5, tied: true);

        $guide = $this->article('900', 'איך בוחרים מסור אנכי', 'x');
        $this->inShop(fn () => $guide->forceFill(['url' => 'https://store.test/guide/'])->save());
        $this->inShop(fn () => EnrichmentContentProduct::query()->create([
            'shop_id' => $this->shop->id, 'content_id' => $guide->id, 'product_id' => $jigsaw->id,
            'rank' => 1, 'score' => 1000, 'reasons' => ['mentioned' => true], 'computed_at' => now(),
        ]));
    }

    public function test_a_product_page_gets_superlatives_specs_cross_sells_and_guides_from_checked_facts(): void
    {
        $bank = $this->page('product', '10')
            ->assertOk()
            ->assertHeader('Access-Control-Allow-Origin')
            ->json();

        $this->assertTrue($bank['enabled']);
        $this->assertFalse($bank['preview']);
        $this->assertSame($this->shop->id, $bank['shop']);
        $this->assertSame(['selector' => 'form.cart', 'position' => 'after', 'floating' => true], $bank['placement']);
        $this->assertSame(['highlights', 'specs', 'complement', 'guides'], array_column($bank['sections'], 'candidate'), 'where the model stands is part of what to know');

        [$position, $specs, $complement, $guides] = $bank['sections'];

        $this->assertSame('משקל הכלי: הנמוך ביותר מבין 9 דגמים (מסור אנכי · נטען) · 1.6 ק״ג', $position['lines'][0]['text']);
        $this->assertSame('מחיר: מהנמוכים ביותר מבין 5 דגמים (מסור אנכי · נטען · גוף בלבד)', $position['lines'][1]['text'], 'a tie never says "the lowest"');
        $this->assertEquals(499, $position['lines'][1]['price'], 'the widget checks this against the live price');
        $this->assertSame($position['lines'][0]['text'], $bank['teaser']['text']);

        $this->assertSame([
            ['label' => 'סוג', 'value' => 'מסור אנכי'],
            ['label' => 'מקור כוח', 'value' => 'נטען'],
            ['label' => 'משקל הכלי', 'value' => '1.6 ק״ג'],
            ['label' => 'מנוע ללא פחמים', 'value' => 'כן'],
        ], array_map(fn (array $row): array => ['label' => $row['label'], 'value' => $row['value']], $specs['specs']), 'approved facts only, in vocabulary order');
        $this->assertSame('jigsaw', explode('|', $bank['compare']['key'])[1], 'kept for comparing with another jigsaw later');
        $this->assertContains(['weight_kg', 'משקל הכלי', '1.6 ק״ג'], $bank['compare']['rows']);

        $this->assertSame(['12', '11'], array_column($complement['products'], 'id'), 'cross-sells in the merchant order, in stock, no upsells');
        $this->assertSame('https://store.test/battery.jpg', $complement['products'][1]['image']);
        $this->assertTrue($complement['products'][0]['needs_options'], 'a length to choose sends the shopper to the product page');
        $this->assertSame('מחיר למטר', $complement['products'][0]['price_note']);
        $this->assertArrayNotHasKey('needs_options', $complement['products'][1]);
        $this->assertSame('guide_card', $guides['model']);
        $this->assertSame('https://store.test/guide/', $guides['guides'][0]['url']);

        $english = $this->page('product', '10', locale: 'en')->json();
        $this->assertSame('Lowest Tool weight of 9 models (Jigsaw · Cordless) · 1.6 kg', $english['sections'][0]['lines'][0]['text']);
        $this->assertSame('ltr', $english['dir']);
    }

    public function test_the_whatsapp_strip_carries_the_shop_wording_hours_and_message(): void
    {
        $this->assertNull($this->page('product', '10')->json('contact'), 'off until a shop turns it on');

        Features::override('widget.whatsapp', true, $this->shop->id);
        Cache::flush();
        $this->assertNull($this->page('product', '10')->json('contact'), 'and until it has a number');

        Settings::set('widget.whatsapp_number', '+972 50-123-4567', $this->shop->id);
        Settings::set('widget.whatsapp_title', 'רוצה שנשלח לך סרטון של המוצר?', $this->shop->id);
        Settings::set('widget.hours_friday', '09:00-13:00', $this->shop->id);
        Settings::set('widget.hours_thursday', '', $this->shop->id);
        Settings::set('widget.whatsapp_when_offline', 'hide', $this->shop->id);
        Cache::flush();

        $contact = $this->page('product', '10')->json('contact');

        $this->assertSame('972501234567', $contact['number'], 'digits only, as WhatsApp links need');
        $this->assertSame('רוצה שנשלח לך סרטון של המוצר?', $contact['title']);
        $this->assertSame('לשיחה בוואטסאפ', $contact['button'], 'what the shop did not write comes from the default');
        $this->assertStringContainsString(':product', $contact['message'], 'the widget fills in the product and the page');
        $this->assertSame(
            ['09:00-18:00', '09:00-18:00', '09:00-18:00', '09:00-18:00', '', '09:00-13:00', ''],
            $contact['hours'],
            'one entry per day, Sunday first: Thursday and Saturday closed, Friday its own',
        );
        $this->assertSame('Asia/Jerusalem', $contact['timezone']);
        $this->assertTrue($contact['hide_when_offline']);

        $this->assertNotNull($this->page('content', '900')->json('contact'), 'articles get the strip too');
    }

    public function test_the_shop_picks_circles_or_the_assistant_and_words_its_sign_up(): void
    {
        $this->assertSame('circles', $this->page('product', '10')->json('layout'), 'circles unless the shop chooses otherwise');

        Settings::set('widget.layout', 'chat', $this->shop->id);
        Features::override('shoppers.signup', true, $this->shop->id);
        Settings::set('shoppers.signup_title', 'הירשמו לאתר וקבלו 5% הנחה', $this->shop->id);
        Settings::set('shoppers.signup_note', 'וגם נשמור לכם את המוצרים שראיתם, לכל מכשיר.', $this->shop->id);
        Cache::flush();

        $bank = $this->page('product', '10')->json();
        $this->assertSame('chat', $bank['layout']);
        $this->assertSame('הירשמו לאתר וקבלו 5% הנחה', $bank['signup']['title']);
        $this->assertSame('וגם נשמור לכם את המוצרים שראיתם, לכל מכשיר.', $bank['signup']['note']);
        $this->assertStringContainsString('היסטוריית הפעילות', $bank['signup']['consent'], 'the built-in consent names the activity history');
        $this->assertArrayHasKey('chat_teaser', $bank['labels']);

        Settings::set('shoppers.signup_note', '', $this->shop->id);
        Cache::flush();
        $this->assertArrayNotHasKey('note', $this->page('product', '10')->json('signup'), 'an empty line is left out');
    }

    public function test_the_popularity_line_says_how_often_the_product_was_added_and_bought(): void
    {
        $this->assertNull($this->page('product', '10')->json('popularity'), 'nothing counted yet');

        $counted = function (array $row): void {
            app(TenantContext::class)->run($this->shop->id, fn () => AnalyticsPopularity::query()->updateOrCreate(
                ['shop_id' => $this->shop->id, 'product_external_id' => '10'],
                $row + ['units' => 0, 'score' => 0, 'rank' => 1, 'window_days' => 30, 'computed_at' => now()],
            ));
            Cache::flush();
        };

        // Two adds prove nothing: kept from shoppers, but the team's page still sees the numbers.
        $counted(['adds' => 2, 'orders' => 0, 'popular' => false]);
        $this->assertNull($this->page('product', '10')->json('popularity'));
        $explained = app(BuildPageBank::class)->handle($this->shop->id, 'product', '10', 'he', explain: true);
        $this->assertSame(2, $explained['explain']['popularity']['']['adds']);
        $this->assertSame(3, $explained['explain']['popularity']['']['min_count']);

        $counted(['adds' => 27, 'orders' => 4, 'popular' => true]);
        $popularity = $this->page('product', '10')->json('popularity');
        $this->assertSame('נוסף לסל 27 פעמים והוזמן 4 פעמים ב־30 הימים האחרונים', $popularity['text']);
        $this->assertSame('פופולרי בחנות', $popularity['badge']);
        $this->assertTrue($popularity['popular']);
        $this->assertSame('Added to the cart 27 times and ordered 4 times in the last 30 days', $this->page('product', '10', locale: 'en')->json('popularity.text'));

        $counted(['adds' => 1, 'orders' => 5, 'popular' => false]);
        $popularity = $this->page('product', '10')->json('popularity');
        $this->assertSame('הוזמן 5 פעמים ב־30 הימים האחרונים', $popularity['text'], 'only the count that reached the minimum');
        $this->assertNull($popularity['badge']);

        $counted(['adds' => 2, 'orders' => 1, 'popular' => true]);
        $popularity = $this->page('product', '10')->json('popularity');
        $this->assertNull($popularity['text']);
        $this->assertSame('פופולרי בחנות', $popularity['badge'], 'the mark alone when the numbers are small');

        $this->assertNull($this->page('content', '900')->json('popularity'), 'products only');

        Features::override('widget.popularity', false, $this->shop->id);
        Cache::flush();
        $this->assertNull($this->page('product', '10')->json('popularity'));
    }

    public function test_the_banner_offers_questions_shoppers_really_asked_here(): void
    {
        $this->assertSame([], $this->page('product', '10')->json('questions'), 'nothing asked yet');

        $save = function (string $question, string $outcome, int $asked, string $status = AssistantAnswer::SHOWN): void {
            app(TenantContext::class)->run($this->shop->id, fn () => AssistantAnswer::query()->create([
                'shop_id' => $this->shop->id,
                'product_id' => CatalogProduct::query()->where('external_id', '10')->value('id'),
                'question_key' => substr(hash('sha256', $question), 0, 64),
                'question' => $question,
                'answer' => $outcome === AssistantAnswer::ANSWERED ? 'תשובה.' : null,
                'outcome' => $outcome,
                'status' => $status,
                'prompt_version' => 2,
                'asked_count' => $asked,
                'last_asked_at' => now(),
            ]));
            Cache::flush();
        };

        $save('האם זה מתאים לחוץ?', AssistantAnswer::ANSWERED, 2);
        $save('אפשר לנסר איתו מתכת?', AssistantAnswer::ANSWERED, 9);
        $save('מה מזג האוויר מחר?', AssistantAnswer::OUT_OF_SCOPE, 30);
        $save('כמה הוא שוקל בדיוק?', AssistantAnswer::NO_INFO, 40);
        $save('שאלה שהוסתרה', AssistantAnswer::ANSWERED, 50, AssistantAnswer::HIDDEN);

        // Most asked first, and only questions that were really answered here.
        $this->assertSame(['אפשר לנסר איתו מתכת?', 'האם זה מתאים לחוץ?'], $this->page('product', '10')->json('questions'));
        $this->assertSame(2, $this->page('product', '10')->json('asked'), 'a question with no answer is not one the banner offers');

        Features::override('assistant.on_products', false, $this->shop->id);
        Cache::flush();
        $this->assertSame([], $this->page('product', '10')->json('questions'));
    }

    public function test_the_banner_carries_what_the_shop_promises_and_what_the_product_is_made_of(): void
    {
        $this->assertSame([], $this->page('product', '10')->json('assurances'), 'nothing read yet');

        $promise = function (array $row): void {
            app(TenantContext::class)->run($this->shop->id, fn () => EnrichmentFact::query()->create($row + [
                'shop_id' => $this->shop->id,
                'kind' => FactKind::Promise,
                'origin' => FactOrigin::Code,
                'status' => FactStatus::Approved,
                'input_hash' => bin2hex(random_bytes(8)),
                'model' => 'code',
            ]));
            Cache::flush();
        };

        $product = app(TenantContext::class)->run($this->shop->id, fn () => CatalogProduct::query()->where('external_id', '10')->firstOrFail());

        $promise(['key' => 'free_shipping', 'value_text' => '500', 'quote' => 'משלוח חינם מעל 500 ש"ח.']);
        $promise(['key' => 'returns', 'value_text' => '14 יום', 'quote' => 'ניתן להחזיר מוצר תוך 14 יום.']);
        $promise(['key' => 'pure_material', 'value_text' => 'כותנה', 'quote' => '100% כותנה.', 'product_id' => $product->id]);
        $promise(['key' => 'handmade', 'value_text' => null, 'quote' => 'עבודת יד.', 'product_id' => $product->id]);

        $assurances = collect($this->page('product', '10')->json('assurances'))->keyBy('scope');

        // The refund window leads, because that is what a shopper weighs first.
        $this->assertSame('החזר תוך 14 יום', $assurances['shop']['text']);
        $this->assertSame('מתוך התקנון של החנות · משלוח חינם מעל 500', $assurances['shop']['note']);
        $this->assertSame('עבודת יד · 100% כותנה', $assurances['product']['text']);
        $this->assertSame('מתוך תיאור המוצר', $assurances['product']['note']);

        $english = collect($this->page('product', '10', locale: 'en')->json('assurances'))->keyBy('scope');
        $this->assertSame('Refund within 14 יום', $english['shop']['text']);
        $this->assertSame('Hand made · 100% כותנה', $english['product']['text'], 'the detail stays as the store wrote it');

        // Another product page sees the shop's promises, never this product's own.
        $other = collect($this->page('product', '11')->json('assurances'))->keyBy('scope');
        $this->assertTrue($other->has('shop'));
        $this->assertFalse($other->has('product'));

        // An article page carries what the shop promises, since that is true anywhere.
        $this->assertSame('shop', $this->page('content', '900')->json('assurances.0.scope'));

        Features::override('widget.promises', false, $this->shop->id);
        Cache::flush();
        $this->assertSame([], $this->page('product', '10')->json('assurances'));
    }

    public function test_an_article_gets_its_matched_products(): void
    {
        $bank = $this->page('content', '900')->assertOk()->json();

        $this->assertSame(['article_products'], array_column($bank['sections'], 'candidate'));
        $this->assertSame('10', $bank['sections'][0]['products'][0]['id']);
        $this->assertStringContainsString('משקל הכלי', $bank['sections'][0]['products'][0]['reason']);
        $this->assertSame('מוצר אחד שמתאים למדריך', $bank['teaser']['text']);
    }

    public function test_placement_and_switches_come_from_the_shop_settings(): void
    {
        Settings::set('widget.product_selector', '.my-theme .summary', $this->shop->id);
        Settings::set('widget.product_position', 'before', $this->shop->id);
        Features::override('widget.on_content', false, $this->shop->id);
        Cache::flush();

        $this->assertSame(['selector' => '.my-theme .summary', 'position' => 'before', 'floating' => true], $this->page('product', '10')->json('placement'));

        $article = $this->page('content', '900')->json();
        $this->assertFalse($article['enabled']);
        $this->assertSame([], $article['sections']);

        $this->assertSame([], $this->page('product', '999')->assertOk()->json('sections'), 'an unknown product is an empty page, not an error');
    }

    public function test_the_page_endpoint_refuses_other_sites_and_bad_input_and_knows_the_team_preview(): void
    {
        $this->page('product', '10', origin: 'https://evil.test')->assertStatus(403);
        $this->page('cart', '10')->assertStatus(422);
        $this->page('product', '10<script>')->assertStatus(422);
        $this->get('/api/v1/widget/000000000000000000000000/page?type=product&id=10')->assertStatus(404);

        $key = StoreConnection::forSite($this->site)->previewKey();
        $this->assertSame(substr(hash_hmac('sha256', 'let-agents-preview', hash('sha256', self::TOKEN)), 0, 32), $key);
        $this->assertTrue($this->page('product', '10', preview: $key)->json('preview'));
        $this->assertFalse($this->page('product', '10', preview: str_repeat('a', 32))->json('preview'));
    }

    public function test_the_script_is_served_with_a_version_tag(): void
    {
        $response = $this->get('/api/v1/widget/let-agents.js')->assertOk()->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
        $this->assertStringContainsString('LetAgentsContext', (string) $response->getContent());

        $this->get('/api/v1/widget/let-agents.js', ['If-None-Match' => $response->headers->get('ETag')])->assertStatus(304);
    }

    public function test_the_operator_gets_preview_links_and_the_placement(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        $key = StoreConnection::forSite($this->site)->previewKey();

        // A shop-owned screen opens once the panel is inside a shop, the way the picker sets it.
        $this->post('/admin/shop', ['shop' => $this->shop->id]);

        foreach (['he', 'en'] as $locale) {
            $this->withHeader('Accept-Language', $locale)
                ->get('/operator/storefront-preview?shop='.$this->shop->id)
                ->assertOk()
                ->assertSee('https://store.test/product/jigsaw/?let_agents_preview='.$key, false)
                ->assertSee('https://store.test/guide/?let_agents_preview='.$key, false)
                ->assertSee('form.cart');
        }
    }

    private function page(string $type, string $id, string $locale = 'he', string $origin = 'https://store.test', ?string $preview = null): TestResponse
    {
        $query = http_build_query(array_filter(['type' => $type, 'id' => $id, 'locale' => $locale, 'preview' => $preview]));

        return $this->get("/api/v1/widget/{$this->site}/page?{$query}", ['Origin' => $origin]);
    }

    private function fact(CatalogProduct $product, string $kind, string $key, ?string $text = null, ?float $number = null, ?string $unit = null, string $status = 'approved'): void
    {
        $this->inShop(fn () => EnrichmentFact::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => $product->id, 'vocabulary_id' => $this->vocabulary->id,
            'kind' => $kind, 'key' => $key, 'value_text' => $text, 'value_number' => $number, 'unit' => $unit,
            'origin' => 'code+model', 'status' => $status, 'input_hash' => 'x',
        ]));
    }

    /** @param array<string, string> $facets */
    private function ranking(CatalogProduct $product, string $metric, string $direction, float $value, ?string $unit, array $facets, int $size, bool $tied = false): void
    {
        $this->inShop(fn () => EnrichmentRanking::query()->create([
            'shop_id' => $this->shop->id, 'product_id' => $product->id, 'metric' => $metric, 'direction' => $direction,
            'rank' => 1, 'tied' => $tied, 'set_size' => $size,
            'set_key' => 'power_tools|'.implode('|', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($facets), $facets)),
            'set_facets' => $facets, 'value' => $value, 'unit' => $unit, 'computed_at' => now(),
        ]));
    }

    public function test_a_shop_switches_a_panel_off_and_the_shopper_never_sees_it(): void
    {
        $before = array_column(app(BuildPageBank::class)->handle($this->shop->id, 'product', '10', 'he')['sections'], 'candidate');

        $this->assertContains('complement', $before, 'there is something to switch off');

        Features::override('widget.show_complement', false, $this->shop->id);
        $bank = app(BuildPageBank::class)->handle($this->shop->id, 'product', '10', 'he');
        $after = array_column($bank['sections'], 'candidate');

        $this->assertNotContains('complement', $after, 'off means gone');
        $this->assertSame(array_values(array_diff($before, ['complement'])), $after, 'and nothing else moved');

        // A panel that is off cannot be the line the shopper is greeted with either.
        $this->assertNotSame('complement', $bank['teaser']['candidate'] ?? null);
    }

    public function test_the_bank_carries_the_untouched_order_and_the_share_of_shoppers_held_out(): void
    {
        Settings::set('analytics.holdout_percent', 15, $this->shop->id);

        $bank = app(BuildPageBank::class)->handle($this->shop->id, 'product', '10', 'he');

        $this->assertSame(15, $bank['holdout_percent']);
        $this->assertSame(
            array_column($bank['sections'], 'candidate'),
            $bank['baseline_order'],
            'with nothing learned yet both orders agree, and both are sent so a control group can be served',
        );
    }
}
