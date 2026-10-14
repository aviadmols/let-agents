<?php

namespace App\Modules\TryOn\Tests;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ImageModel;
use App\Modules\Ai\Contracts\ImageReply;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Contracts\VisionModel;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactOrigin;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Tenancy\Models\Shop;
use App\Modules\TryOn\Actions\GatePhoto;
use App\Modules\TryOn\Filament\Operator\Pages\TryOnLog;
use App\Modules\TryOn\Models\TryOnJob;
use App\Modules\TryOn\Models\TryOnPhoto;
use App\Modules\TryOn\Models\TryOnStep;
use App\Modules\TryOn\Support\Picture;
use App\Modules\TryOn\Support\TryOnClasses;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A product on the shopper's own photo: the photo is checked in code and by a look of a vision model
 * (two people, a child, a blurred photo: refused), what it leaves open is asked first (which finger),
 * the picture is made and checked by another family, tried again with the correction, and every step
 * is kept for the operator with its prompt, pictures and cost.
 */
final class TryOnTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'lat_tryon_test_token_0123456789abcdef';

    private const VID = 'anon-0123456789abcdefghij';

    private Shop $shop;

    private string $site;

    /** @var list<array<string, mixed>> what the vision model says of the next photos */
    public array $gate = [];

    /** @var list<array<string, mixed>> what the checker says of the next pictures */
    public array $checks = [];

    public int $pictures = 0;

    /** @var list<string> the writer's inputs */
    public array $written = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->shop = Shop::factory()->create(['domain' => 'www.store.test']);
        Features::override('try_on.enabled', true, $this->shop->id);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $this->shop->id, 'site_url' => 'https://www.store.test', 'access_token' => self::TOKEN]));
        $this->site = SiteKeys::site(self::TOKEN);

        $this->product('ring-1', 'טבעת זהב עם יהלום', 'ring');
        $this->product('drill-1', 'מברגת אימפקט', null);
        Http::fake(['img.test/*' => Http::response(self::picture(700, 700), 200, ['Content-Type' => 'image/jpeg'])]);

        $test = $this;
        $this->app->instance(VisionModel::class, new class($test) implements VisionModel
        {
            public function __construct(private readonly TryOnTest $test) {}

            public function jsonWithImage(AiProviderName $provider, string $model, string $system, string $user, string $mime, string $bytes, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $data = str_contains($system, 'You check a picture') ? array_shift($this->test->checks) : array_shift($this->test->gate);

                return new ModelReply($data ?? [], 1200, 150);
            }
        });
        $this->app->instance(ChatModel::class, new class($test) implements ChatModel
        {
            public function __construct(private readonly TryOnTest $test) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->test->written[] = $user;

                return new ModelReply(['prompt' => 'Put the ring on the named finger. Keep everything else.'], 900, 80);
            }
        });
        $this->app->instance(ImageModel::class, new class($test) implements ImageModel
        {
            public function __construct(private readonly TryOnTest $test) {}

            public function generate(AiProviderName $provider, string $model, string $prompt, array $images): ImageReply
            {
                $this->test->pictures++;

                return new ImageReply(TryOnTest::picture(1024, 1024), 'image/jpeg', 6.5, 'pred_'.$this->test->pictures);
            }
        });
    }

    public function test_a_photo_is_checked_in_code_then_by_a_look_and_two_people_or_a_child_are_refused(): void
    {
        $this->assertSame('consent_required', $this->upload(self::picture(900, 900), consent: false)['reject_code']);
        $this->assertSame('too_small', $this->upload(self::picture(300, 300))['reject_code'], 'refused in code: no model looked');
        $this->assertSame('blurry', $this->upload(self::flat(900, 900))['reject_code']);
        $this->assertSame([], $this->gate, 'nothing was asked of a model');

        $this->gate[] = $this->hand(['people_count' => 2]);
        $two = $this->upload(self::picture(900, 900, 1));
        $this->assertSame('several_people', $two['reject_code']);
        $this->assertSame('יש בתמונה יותר מאדם אחד. העלו תמונה שלכם בלבד.', $two['message']);

        $this->gate[] = $this->hand(['minor_present' => true]);
        $this->assertSame('minor', $this->upload(self::picture(900, 900, 2))['reject_code']);

        $this->gate[] = $this->hand();
        $ok = $this->upload(self::picture(900, 900, 3));
        $this->assertSame('ok', $ok['status']);
        $this->assertContains('ring', $ok['usable']);

        // The same photo again is not looked at again.
        $this->assertSame($ok['photo'], $this->upload(self::picture(900, 900, 3))['photo']);
        $this->assertSame(1, $this->inShop(fn () => TryOnStep::query()->where('step', 'vision_gate')->where('photo_id', $ok['photo'])->count()));
    }

    public function test_which_finger_is_asked_first_then_the_picture_is_made_checked_and_kept_with_every_step(): void
    {
        $this->gate[] = $this->hand(['hands_visible' => 2]);
        $photo = $this->upload(self::picture(900, 900))['photo'];

        $asked = $this->start($photo, 'ring-1')->assertOk()->json('data');
        $this->assertSame('needs_answer', $asked['status']);
        $this->assertSame(['finger', 'hand'], array_column($asked['questions'], 'key'));
        $this->assertSame('על איזו אצבע לשים את הטבעת?', $asked['questions'][0]['question']);
        $this->assertSame(['index', 'middle', 'ring', 'pinky', 'thumb'], array_column($asked['questions'][0]['options'], 'key'), 'every finger the photo shows, the thumb last');
        $this->assertSame('קמיצה', $asked['questions'][0]['options'][2]['label']);
        $this->assertSame(0, $this->pictures, 'nothing is made before it is answered');

        $this->assertSame('needs_answer', $this->start($photo, 'ring-1', ['finger' => 'elbow', 'hand' => 'left'])->json('data.status'), 'an answer that is not an option is asked again');

        // A different-looking ring (2) is refused; the same kind with small differences (3) would pass.
        $this->checks[] = $this->verdict(['product_similarity' => 2, 'fix' => 'The stone is smaller than in the store picture; keep its size.']);
        $this->checks[] = $this->verdict();
        $made = $this->start($photo, 'ring-1', ['finger' => 'ring', 'hand' => 'left'])->assertStatus(202)->json('data');

        // The queue runs at once in tests: refused once, made again with the correction, then shown.
        $state = $this->getJson("/api/v1/try-on/{$this->site}/tries/{$made['id']}?vid=".self::VID, ['Origin' => 'https://www.store.test'])->assertOk()->json('data');
        $this->assertSame('ready', $state['status']);
        $this->assertSame('התמונה נוצרה באמצעות AI ויכולה להיות לא מדויקת.', $state['note']);
        $this->assertSame(2, $this->pictures);
        $this->assertStringContainsString('The stone is smaller', $this->written[1], 'the second prompt carries the checker\'s correction');
        $this->assertStringContainsString('"finger":"ring"', $this->written[0], 'and the shopper\'s answers');

        $file = $this->get($state['image'])->assertOk();
        $this->assertSame('image/jpeg', $file->headers->get('Content-Type'));
        $this->get(preg_replace('/signature=[^&]+/', 'signature=bad', $state['image']))->assertForbidden();

        $job = $this->inShop(fn () => TryOnJob::query()->find($made['id']));
        $this->assertSame(2, $job->attempts);
        $this->assertGreaterThan(0.07, (float) $job->cost_usd, 'two pictures, two prompts, two checks');
        $steps = $this->inShop(fn () => TryOnStep::query()->where('job_id', $job->id)->orderBy('created_at')->orderBy('id')->pluck('status', 'id')->all());
        $this->assertCount(6, $steps);
        $this->assertSame(['prompt', 'generate', 'check'], $this->inShop(fn () => TryOnStep::query()->where('job_id', $job->id)->where('attempt', 1)->orderBy('created_at')->orderBy('id')->pluck('step')->all()));

        // The same try again is the one already made.
        $this->assertSame($made['id'], $this->start($photo, 'ring-1', ['finger' => 'ring', 'hand' => 'left'])->json('data.id'));
        $this->assertSame(2, $this->pictures);
    }

    public function test_what_cannot_be_tried_is_refused_before_anything_is_spent_and_an_off_shop_has_no_try_on(): void
    {
        $this->gate[] = $this->hand();
        $photo = $this->upload(self::picture(900, 900))['photo'];

        $this->assertSame('not_tryable', $this->start($photo, 'drill-1', [])->json('data.problem'));

        $this->product('sofa-1', 'ספה פינתית', 'furniture');
        $this->assertSame('wrong_subject', $this->start($photo, 'sofa-1', [])->json('data.problem'), 'a hand is no room');
        $this->assertSame(0, $this->pictures);

        $this->getJson("/api/v1/try-on/{$this->site}/config?product=ring-1", ['Origin' => 'https://www.store.test'])->assertOk()->assertJsonPath('data.group', 'jewelry');
        $this->getJson("/api/v1/try-on/{$this->site}/config?product=ring-1", ['Origin' => 'https://evil.test'])->assertForbidden();

        Features::override('try_on.enabled', false, $this->shop->id);
        $this->getJson("/api/v1/try-on/{$this->site}/config?product=ring-1", ['Origin' => 'https://www.store.test'])->assertNotFound();
    }

    public function test_a_picture_the_checker_never_passes_is_not_shown(): void
    {
        $this->gate[] = $this->hand(['fingers_visible' => ['ring']]);
        $photo = $this->upload(self::picture(900, 900))['photo'];
        $this->checks[] = $this->verdict(['anatomy_ok' => false, 'fix' => 'Six fingers.']);
        $this->checks[] = $this->verdict(['anatomy_ok' => false, 'fix' => 'Six fingers again.']);

        $id = $this->start($photo, 'ring-1')->assertStatus(202)->json('data.id');
        $state = $this->getJson("/api/v1/try-on/{$this->site}/tries/{$id}?vid=".self::VID, ['Origin' => 'https://www.store.test'])->json('data');

        $this->assertSame('failed', $state['status']);
        $this->assertSame('check_failed', $state['failure']);
        $this->assertNull($state['image']);
    }

    public function test_the_operator_sees_every_step_and_the_shop_manager_only_counts(): void
    {
        $this->gate[] = $this->hand(['fingers_visible' => ['ring']]);
        $photo = $this->upload(self::picture(900, 900))['photo'];
        $this->checks[] = $this->verdict();
        $id = $this->start($photo, 'ring-1')->json('data.id');

        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        app(TenantContext::class)->set($this->shop->id);
        Livewire::test(TryOnLog::class)->assertSeeHtml('data-try-on-job="'.$id.'"')->call('open', $id)
            ->assertSeeHtml('data-try-on-step="vision_gate"')->assertSeeHtml('data-try-on-step="generate"')->assertSeeHtml('data-try-on-result')
            ->assertSee('Put the ring on the named finger')->assertSee('google/nano-banana');

        $manager = User::factory()->create();
        $manager->attachShop($this->shop, ShopRole::Owner);
        $html = $this->actingAs($manager)->withHeader('Accept-Language', 'he')->get("/merchant/{$this->shop->slug}/try-on")->assertOk()->getContent();
        $this->assertStringContainsString('החודש: 1 מדידות, 1 תמונות הוצגו.', $html);
        $this->assertDoesNotMatchRegularExpression('/nano-banana|claude|gpt|\$0\./iu', strip_tags($html));
    }

    public function test_the_widget_loads_try_on_only_where_it_is_on_and_the_window_gets_its_words_from_the_server(): void
    {
        $page = fn (string $id) => $this->getJson("/api/v1/widget/{$this->site}/page?type=product&id={$id}&locale=he", ['Origin' => 'https://www.store.test'])->assertOk()->json();

        $this->assertSame(['class' => 'ring'], $page('ring-1')['tryon']);
        $this->assertNull($page('drill-1')['tryon'], 'a product the scan did not mark');

        $config = $this->getJson("/api/v1/try-on/{$this->site}/config?product=ring-1&locale=he", ['Origin' => 'https://www.store.test'])->json('data');
        $this->assertSame('העלו תמונה של היד', $config['labels']['upload_title']['jewelry']);
        $this->assertStringContainsString('90 יום', $config['labels']['consent']);
        $this->assertSame('התמונה נוצרה באמצעות AI ויכולה להיות לא מדויקת.', $config['note']);
        $this->assertSame(['selector' => '', 'position' => 'after'], $config['anchor'], 'no place chosen: next to the add-to-cart form');

        // The shop chooses where the button goes on a page without the widget block.
        Settings::set('try_on.anchor_selector', '.product-form__buttons', $this->shop->id);
        Settings::set('try_on.anchor_position', 'before', $this->shop->id);
        $this->assertSame(['selector' => '.product-form__buttons', 'position' => 'before'], $this->getJson("/api/v1/try-on/{$this->site}/config?product=ring-1&locale=he", ['Origin' => 'https://www.store.test'])->json('data.anchor'));

        $script = $this->get('/api/v1/try-on/let-agents-tryon.js')->assertOk();
        $this->assertStringContainsString('application/javascript', (string) $script->headers->get('Content-Type'));
        $this->assertStringContainsString('LetAgentsTryOn', $script->getContent());

        Features::override('try_on.enabled', false, $this->shop->id);
        cache()->flush();
        $this->assertNull($page('ring-1')['tryon'], 'off: the widget never loads it');
    }

    public function test_the_team_tries_it_on_its_own_store_with_the_preview_key_while_it_is_off_for_shoppers(): void
    {
        Features::override('try_on.enabled', false, $this->shop->id);
        $key = app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->where('shop_id', $this->shop->id)->first())->previewKey();
        $origin = ['Origin' => 'https://www.store.test'];
        $page = fn (string $query) => $this->getJson("/api/v1/widget/{$this->site}/page?type=product&locale=he&{$query}", $origin)->assertOk()->json('tryon');

        // A shopper: nothing, even asking for it.
        $this->assertNull($page('id=ring-1&tryon=1'));
        $this->getJson("/api/v1/try-on/{$this->site}/config?product=ring-1", $origin)->assertNotFound();
        $this->getJson("/api/v1/try-on/{$this->site}/config?product=ring-1&preview=wrongkey0000000000000000000000ab", $origin)->assertNotFound();

        // The team, with the key: the product's own class, or the one it names on a product the scan did not mark.
        $this->assertSame(['class' => 'ring', 'test' => true], $page("id=ring-1&preview={$key}&tryon=1"));
        $this->assertSame(['class' => 'top', 'test' => true], $page("id=drill-1&preview={$key}&tryon=top"));
        $this->assertSame(['class' => 'auto', 'test' => true], $page("id=drill-1&preview={$key}&tryon=1"), 'not marked and no class named: the class its name says');
        $this->assertFalse($this->getJson("/api/v1/try-on/{$this->site}/config?product=drill-1&preview={$key}&class=auto", $origin)->assertOk()->json('data.tryable'), 'a drill says no class');
        $this->assertTrue($this->getJson("/api/v1/try-on/{$this->site}/config?product=drill-1&preview={$key}&class=ring", $origin)->assertOk()->json('data.tryable'));
        $this->assertFalse($this->getJson("/api/v1/try-on/{$this->site}/config?product=drill-1&preview={$key}&class=rocket", $origin)->assertOk()->json('data.tryable'), 'only a real try-on class');

        // The whole try runs for the team, and costs like any other.
        $this->gate[] = $this->hand(['fingers_visible' => ['ring']]);
        $photo = $this->call('POST', "/api/v1/try-on/{$this->site}/photos", ['vid' => self::VID, 'consent' => '1', 'preview' => $key, 'photo' => UploadedFile::fake()->createWithContent('me.jpg', self::picture(900, 900))],
            server: ['HTTP_ORIGIN' => 'https://www.store.test', 'HTTP_ACCEPT' => 'application/json'])->assertOk()->json('data.photo');
        $this->checks[] = $this->verdict();
        $made = $this->postJson("/api/v1/try-on/{$this->site}/tries", ['vid' => self::VID, 'photo' => $photo, 'product' => 'drill-1', 'preview' => $key, 'class' => 'ring'], $origin)->assertStatus(202)->json('data');
        $this->assertSame('ready', $this->getJson("/api/v1/try-on/{$this->site}/tries/{$made['id']}?vid=".self::VID."&preview={$key}", $origin)->json('data.status'));
        $this->postJson("/api/v1/try-on/{$this->site}/tries", ['vid' => self::VID, 'photo' => $photo, 'product' => 'drill-1', 'class' => 'ring'], $origin)->assertNotFound();

        // The team's test has no daily count: a second try the same day, over a limit of one.
        Settings::set('try_on.jobs_per_visitor_per_day', 1, $this->shop->id);
        $this->checks[] = $this->verdict();
        $this->postJson("/api/v1/try-on/{$this->site}/tries", ['vid' => self::VID, 'photo' => $photo, 'product' => 'ring-1', 'preview' => $key], $origin)->assertStatus(202);

        // The operator finds the link on the log.
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        app(TenantContext::class)->set($this->shop->id);
        Livewire::test(TryOnLog::class)->assertSeeHtml('data-try-on-test')->assertSee("let_agents_preview={$key}&let_agents_tryon=1");
    }

    public function test_the_team_tries_a_product_the_catalog_has_not_read_by_its_page_name_and_picture(): void
    {
        Features::override('try_on.enabled', false, $this->shop->id);
        $key = app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->where('shop_id', $this->shop->id)->first())->previewKey();
        $origin = ['Origin' => 'https://www.store.test'];
        $page = '&title='.rawurlencode('שרשרת איטלקית חוליות אובליות 4.35 מ"מ').'&image='.rawurlencode('https://www.store.test/cdn/shop/files/chain.jpg');
        $config = fn (string $query) => $this->getJson("/api/v1/try-on/{$this->site}/config?product=new-777&preview={$key}{$query}", $origin)->assertOk()->json('data');

        $this->assertSame([true, 'necklace'], [$config($page)['tryable'], $config($page)['class']], 'a chain: its name says necklace');
        $this->assertFalse($config(str_replace('www.store.test', 'evil.test', $page))['tryable'], 'the server fetches the picture: only from the store or Shopify');
        $this->assertFalse($config('')['tryable'], 'no name and picture: nothing to try');
        $this->getJson("/api/v1/try-on/{$this->site}/config?product=new-777{$page}", $origin)->assertNotFound();

        Http::fake(['www.store.test/*' => Http::response(self::picture(700, 700), 200, ['Content-Type' => 'image/jpeg'])]);
        // A head-and-neck selfie: its main subject is the face, and the neck shows. That serves a necklace.
        $this->gate[] = $this->hand(['subject' => 'face', 'parts_visible' => ['face', 'neck'], 'hands_visible' => 0, 'fingers_visible' => []]);
        $photo = $this->call('POST', "/api/v1/try-on/{$this->site}/photos", ['vid' => self::VID, 'consent' => '1', 'preview' => $key, 'photo' => UploadedFile::fake()->createWithContent('me.jpg', self::picture(900, 900))],
            server: ['HTTP_ORIGIN' => 'https://www.store.test', 'HTTP_ACCEPT' => 'application/json'])->assertOk()->json('data.photo');
        $this->checks[] = $this->verdict();
        $made = $this->postJson("/api/v1/try-on/{$this->site}/tries", ['vid' => self::VID, 'photo' => $photo, 'product' => 'new-777', 'preview' => $key, 'class' => '',
            'title' => 'שרשרת איטלקית חוליות אובליות 4.35 מ"מ', 'image' => 'https://www.store.test/cdn/shop/files/chain.jpg'], $origin)->assertStatus(202)->json('data');

        $this->assertSame('ready', $this->getJson("/api/v1/try-on/{$this->site}/tries/{$made['id']}?vid=".self::VID."&preview={$key}", $origin)->json('data.status'));
        $job = app(TenantContext::class)->run($this->shop->id, fn () => TryOnJob::query()->findOrFail($made['id']));
        $this->assertNull($job->product_id);
        $this->assertSame(['necklace', 'שרשרת איטלקית חוליות אובליות 4.35 מ"מ'], [$job->tryon_class, $job->productTitle()]);
    }

    public function test_the_operator_changes_the_picture_model_and_the_shop_cap_in_the_hub_and_they_stay(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
        app(TenantContext::class)->set($this->shop->id);

        Livewire::test(TryOnLog::class, ['tab' => 'settings'])
            ->assertSeeHtml('data-setting="model_jewelry"')
            ->set('values.model_jewelry', 'google/nano-banana-pro')
            ->set('values.monthly_cap_usd', '35')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('values.model_jewelry', 'google/nano-banana-pro');

        $this->assertSame('google/nano-banana-pro', Settings::get('try_on.model_jewelry'), 'a setting of the whole system');
        $this->assertSame(35.0, (float) Settings::get('try_on.monthly_cap_usd', $this->shop->id), 'this shop only');
        $this->assertSame(20.0, (float) Settings::get('try_on.monthly_cap_usd'), 'the default stays');

        Livewire::test(TryOnLog::class, ['tab' => 'settings'])->set('values.monthly_cap_usd', 'many')->call('save')->assertHasErrors(['values.monthly_cap_usd']);

        // A failed try says why, in words.
        $this->gate[] = $this->hand(['fingers_visible' => ['ring']]);
        $photo = $this->upload(self::picture(900, 900))['photo'];
        $this->checks[] = $this->verdict(['product_similarity' => 1, 'artifacts' => ['another ring']]);
        $this->checks[] = $this->verdict(['product_similarity' => 1, 'artifacts' => ['another ring']]);
        $this->start($photo, 'ring-1')->assertStatus(202);
        Livewire::test(TryOnLog::class, ['tab' => 'tries'])->assertSee('הבודק דחה את התמונה בכל הניסיונות: המוצר בתמונה לא נראה כמו המוצר (another ring)');
    }

    public function test_a_products_name_says_its_class_for_the_teams_test(): void
    {
        $this->assertSame('necklace', TryOnClasses::guess('שרשרת עם תליון טבעת'), 'the first word that says a class');
        $this->assertSame('ring', TryOnClasses::guess("טבעת וינטג' עדינה משובצת פרידוט"));
        $this->assertSame('earrings', TryOnClasses::guess('עגילי חישוק זהב'));
        $this->assertSame('furniture', TryOnClasses::guess('ספה תלת מושבית אפורה'));
        $this->assertSame('dress', TryOnClasses::guess('Linen summer dress'));
        $this->assertNull(TryOnClasses::guess('מקדחה רוטטת 18V'));
    }

    public function test_a_photo_serves_every_item_whose_part_it_shows_not_only_its_main_subject(): void
    {
        $face = ['subject' => 'face', 'parts_visible' => ['face', 'neck']];
        $this->assertTrue(TryOnClasses::fits('necklace', $face), 'the neck shows in a head-and-neck selfie');
        $this->assertTrue(TryOnClasses::fits('earrings', $face));
        $this->assertFalse(TryOnClasses::fits('necklace', ['subject' => 'face', 'parts_visible' => ['face']]), 'a neck covered or out of the photo');
        $this->assertFalse(TryOnClasses::fits('ring', $face));

        [$reason, $usable] = app(GatePhoto::class)->decide($this->hand(['subject' => 'other', 'parts_visible' => [], 'hands_visible' => 0, 'fingers_visible' => []]));
        $this->assertSame(['no_subject', []], [$reason, $usable], 'a mug serves nothing');
    }

    public function test_pictures_are_deleted_after_the_shops_days_and_the_log_stays(): void
    {
        $this->gate[] = $this->hand(['fingers_visible' => ['ring']]);
        $photo = $this->upload(self::picture(900, 900))['photo'];
        $this->checks[] = $this->verdict();
        $id = $this->start($photo, 'ring-1')->json('data.id');
        $job = $this->inShop(fn () => TryOnJob::query()->find($id));
        $this->assertTrue(Storage::disk('local')->exists($job->result_path));

        $this->inShop(fn () => TryOnPhoto::query()->whereKey($photo)->update(['expires_at' => now()->subDay()]));
        $this->artisan('try-on:prune')->assertSuccessful();

        $this->assertFalse(Storage::disk('local')->exists($job->result_path));
        $this->assertNull($this->inShop(fn () => TryOnJob::query()->find($id))->result_path);
        $this->assertGreaterThan(0, $this->inShop(fn () => TryOnStep::query()->where('job_id', $id)->count()), 'what happened and what it cost stay');
    }

    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function upload(string $bytes, bool $consent = true): array
    {
        return $this->call('POST', "/api/v1/try-on/{$this->site}/photos", ['vid' => self::VID, 'consent' => $consent ? '1' : '0', 'photo' => UploadedFile::fake()->createWithContent('me.jpg', $bytes)],
            server: ['HTTP_ORIGIN' => 'https://www.store.test', 'HTTP_ACCEPT' => 'application/json'])->assertOk()->json('data');
    }

    /** @param array<string, string> $answers */
    private function start(string $photo, string $product, array $answers = [])
    {
        return $this->postJson("/api/v1/try-on/{$this->site}/tries", ['vid' => self::VID, 'photo' => $photo, 'product' => $product, 'answers' => $answers], ['Origin' => 'https://www.store.test']);
    }

    /** @return array<string, mixed> */
    private function hand(array $over = []): array
    {
        return $over + ['subject' => 'hand', 'people_count' => 1, 'minor_present' => false, 'quality' => 'ok', 'unsafe' => false, 'hands_visible' => 1,
            'fingers_visible' => ['thumb', 'index', 'middle', 'ring', 'pinky'], 'ears_visible' => 0, 'free_spots' => [], 'scale' => ''];
    }

    /** @return array<string, mixed> */
    private function verdict(array $over = []): array
    {
        return $over + ['product_similarity' => 5, 'identity_kept' => true, 'anatomy_ok' => true, 'proportions_ok' => true, 'placement_ok' => true, 'extra_people' => false, 'artifacts' => [], 'fix' => ''];
    }

    private function product(string $external, string $title, ?string $class): void
    {
        $this->inShop(function () use ($external, $title, $class): void {
            $product = CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $external, 'type' => 'simple', 'status' => 'publish', 'title' => $title,
                'price' => '499.00', 'currency' => 'ILS', 'in_stock' => true, 'purchasable' => true, 'hash' => md5($title), 'image_url' => "https://img.test/{$external}.jpg",
                'payload' => ['title' => $title, 'description' => '', 'categories' => [], 'attributes' => [], 'meta' => []]]);
            if ($class !== null) {
                EnrichmentFact::query()->create(['shop_id' => $this->shop->id, 'product_id' => $product->id, 'kind' => FactKind::TryOn, 'key' => 'class', 'value_text' => $class,
                    'origin' => FactOrigin::Code, 'status' => FactStatus::Approved, 'input_hash' => 'h-'.$external]);
            }
        });
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }

    /** A sharp, light test picture; $seed makes a different one. */
    public static function picture(int $width, int $height, int $seed = 0): string
    {
        mt_srand(42 + $seed);
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 210, 200, 190));
        for ($i = 0; $i < 400; $i++) {
            $color = imagecolorallocate($image, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
            imagefilledrectangle($image, $x = mt_rand(0, $width), $y = mt_rand(0, $height), $x + mt_rand(4, 40), $y + mt_rand(4, 40), $color);
        }
        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    /** One flat colour: nothing in it is sharp. */
    private static function flat(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 200, 200));
        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }
}
