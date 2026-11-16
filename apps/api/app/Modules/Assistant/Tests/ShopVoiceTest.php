<?php

namespace App\Modules\Assistant\Tests;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Ai\Contracts\ChatModel;
use App\Modules\Ai\Contracts\ModelReply;
use App\Modules\Ai\Enums\AiProviderName;
use App\Modules\Assistant\Actions\ReadShopVoice;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Runs\Support\FirstScan;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The store's tone of voice, read in its scan (as Aviad asked, 16.11): a few of its own product descriptions and posts,
 * a small model says the style and a sentence on the voice, and the assistant answers in it. A tone the owner chose is
 * never changed; the same texts are never asked about twice; too few say nothing.
 */
final class ShopVoiceTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    /** @var list<array{system: string, user: string}> */
    private array $calls = [];

    /** @var array<string, mixed> what the model answers */
    private array $reply = ['style' => 'friendly', 'note' => 'פונה ללקוחות בלשון רבים, במשפטים קצרים וקלילים, עם מילה של הומור. ראו https://example.test', 'evidence' => ['תתאהבו בזה']];

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create(['name' => 'קפה ושות׳', 'content_locale' => 'he']);
        $calls = &$this->calls;
        $reply = &$this->reply;
        $this->app->instance(ChatModel::class, new class($calls, $reply) implements ChatModel
        {
            public function __construct(private array &$calls, private array &$reply) {}

            public function json(AiProviderName $provider, string $model, string $system, string $user, int $maxOutputTokens, ?string $reasoningEffort = null): ModelReply
            {
                $this->calls[] = ['system' => $system, 'user' => $user];

                return new ModelReply($this->reply, 400, 60);
            }
        });
    }

    public function test_the_stores_own_words_set_the_tone_its_assistant_answers_in(): void
    {
        $this->products(6);
        $this->inShop(fn () => CatalogContent::query()->create(['shop_id' => $this->shop->id, 'type' => 'post', 'external_id' => 'p1', 'title' => 'איך מכינים אספרסו', 'excerpt' => 'בואו נודה באמת: אספרסו טוב מתחיל בפולים טריים, ואנחנו פה כדי לעזור לכם לבחור אותם.', 'hash' => 'h1']));

        $read = $this->inShop(fn () => app(ReadShopVoice::class)->handle($this->shop->id));

        $this->assertSame('set', $read['status']);
        $this->assertSame('friendly', Settings::get('assistant.answer_tone', $this->shop->id));
        $this->assertSame('פונה ללקוחות בלשון רבים, במשפטים קצרים וקלילים, עם מילה של הומור. ראו', Settings::get('assistant.answer_tone_note', $this->shop->id), 'no link in the voice');
        $sent = json_decode($this->calls[0]['user'], true);
        $this->assertSame('he', $sent['language']);
        $this->assertGreaterThanOrEqual(3, count($sent['samples']));
        $this->assertStringContainsString('אספרסו טוב', implode(' ', $sent['samples']), 'its posts too');
        $this->assertStringNotContainsString('<p>', implode(' ', $sent['samples']), 'its words, never its markup');

        // the same texts: never asked twice
        $this->assertSame('same', $this->inShop(fn () => app(ReadShopVoice::class)->handle($this->shop->id))['status']);
        $this->assertCount(1, $this->calls);

        // its texts changed, the tone still as this step set it: read again
        $this->products(3, 'חדש');
        $this->reply = ['style' => 'warm', 'note' => 'חם ושירותי.', 'evidence' => []];
        $this->assertSame('set', $this->inShop(fn () => app(ReadShopVoice::class)->handle($this->shop->id))['status']);
        $this->assertSame('warm', Settings::get('assistant.answer_tone', $this->shop->id));
    }

    public function test_a_tone_the_owner_chose_is_never_changed(): void
    {
        $this->products(6);
        Settings::set('assistant.answer_tone', 'professional', $this->shop->id);

        $this->assertSame('kept', $this->inShop(fn () => app(ReadShopVoice::class)->handle($this->shop->id))['status']);
        $this->assertSame('professional', Settings::get('assistant.answer_tone', $this->shop->id));
        $this->assertSame([], $this->calls);

        // nor one this step set and the owner then changed
        Settings::set('assistant.answer_tone', 'friendly', $this->shop->id);
        Settings::set('assistant.voice_read', (string) json_encode(['hash' => 'x', 'style' => 'warm', 'note' => '']), $this->shop->id);
        $this->assertSame('kept', $this->inShop(fn () => app(ReadShopVoice::class)->handle($this->shop->id))['status']);
        $this->assertSame([], $this->calls);
    }

    public function test_a_style_out_of_the_four_and_a_long_note_are_kept_in_bounds_and_too_few_texts_ask_nothing(): void
    {
        $this->assertSame('few', $this->inShop(fn () => app(ReadShopVoice::class)->handle($this->shop->id))['status'], 'no product says anything yet');
        $this->assertSame([], $this->calls);

        $this->products(6);
        $this->reply = ['style' => 'sarcastic', 'note' => str_repeat('משפט קצר על הסגנון. ', 30), 'evidence' => []];
        $this->inShop(fn () => app(ReadShopVoice::class)->handle($this->shop->id));
        $this->assertSame('warm', Settings::get('assistant.answer_tone', $this->shop->id), 'a style of the four, else the default');
        $this->assertLessThanOrEqual(280, mb_strlen((string) Settings::get('assistant.answer_tone_note', $this->shop->id)));
        $this->assertStringEndsWith('.', (string) Settings::get('assistant.answer_tone_note', $this->shop->id), 'cut at a sentence\'s end');
    }

    public function test_the_scan_reads_the_voice_after_what_the_store_says_about_itself(): void
    {
        $steps = array_keys(FirstScan::STEPS);
        $this->assertSame(array_search('knowledge', $steps, true) + 1, array_search('voice', $steps, true));
        $this->assertSame(['assistant:voice', ['shop' => '{shop}']], FirstScan::STEPS['voice']);
    }

    private function products(int $n, string $tag = ''): void
    {
        $this->inShop(function () use ($n, $tag): void {
            for ($i = 1; $i <= $n; $i++) {
                CatalogProduct::query()->create(['shop_id' => $this->shop->id, 'external_id' => $tag.$i, 'type' => 'simple', 'status' => 'publish', 'title' => 'פולי קפה '.$tag.$i,
                    'url' => 'https://store.test/'.$tag.$i, 'price' => 60, 'in_stock' => true, 'purchasable' => true, 'hash' => 'h'.$tag.$i,
                    'payload' => ['description' => '<p>תתאהבו בזה: פולי קפה '.$tag.$i.' קלויים אצלנו כל שבוע, עם ניחוחות של שוקולד ואגוזים. תנסו ותגידו לנו מה דעתכם!</p>']]);
            }
        });
    }

    private function inShop(callable $run): mixed
    {
        return app(TenantContext::class)->run((string) $this->shop->id, $run);
    }
}
