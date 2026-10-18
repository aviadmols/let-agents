<?php

namespace App\Modules\Analytics\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Analytics\Actions\RecordOrder;
use App\Modules\Analytics\Actions\RecordVisit;
use App\Modules\Analytics\Filament\Merchant\Pages\ShopSessions;
use App\Modules\Analytics\Models\AnalyticsVisit;
use App\Modules\Analytics\Models\AnalyticsVisitStep;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Sessions, as the designer's panel lays them out: the search window sends a visit's steps; code keeps
 * only the named campaign parameters, counts each step once, and knows where the visit came from; a
 * purchase joins the visitor's visit; the screen filters and opens a visit with its steps in order.
 */
final class SessionsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private StoreConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create(['name' => 'גואטה אביגדור']);
        $this->connection = new StoreConnection;
        $this->connection->shop_id = $this->shop->id;
    }

    public function test_a_visit_keeps_only_campaign_parameters_and_counts_each_step_once(): void
    {
        $body = $this->body('sessionaaaa1', [
            ['id' => 'step0001', 'kind' => 'view', 'type' => 'category', 'path' => '/product-category/plates/?email=a@b.c'],
            ['id' => 'step0002', 'kind' => 'search', 'label' => 'לביד 17', 'n' => 8],
            ['id' => 'step0003', 'kind' => 'cart', 'item' => '123', 'n' => 2],
        ], ['landing' => '/product-category/plates/', 'ref' => 'https://www.google.com/search?q=x', 'params' => ['utm_source' => 'google', 'utm_campaign' => 'mdf-oct', 'gclid' => 'Cj0K', 'email' => 'a@b.c']]);

        $this->record($body);
        $this->record($body);

        $visit = $this->inShop(fn () => AnalyticsVisit::query()->sole());
        $this->assertSame(['utm_source' => 'google', 'utm_campaign' => 'mdf-oct', 'gclid' => 'Cj0K'], $visit->params, 'nothing else of the address');
        $this->assertSame(['google', true, 'mdf-oct', 'google.com'], [$visit->source, $visit->paid, $visit->campaign, $visit->referrer]);
        $this->assertSame([3, 1, ['search', 'cart'], ['לביד 17']], [$visit->steps, $visit->pages, $visit->did, $visit->searches], 'the batch sent twice counts once');
        $this->assertSame('/product-category/plates/', $this->inShop(fn () => AnalyticsVisitStep::query()->where('kind', 'view')->value('path')), 'no query string');
        $this->assertSame(['phone', 'iOS', 'Safari'], [$visit->device, $visit->os, $visit->browser]);
    }

    public function test_where_a_visit_came_from(): void
    {
        $this->assertSame('facebook', RecordVisit::source('l.facebook.com', [])['source']);
        $this->assertSame('instagram', RecordVisit::source('l.instagram.com', ['fbclid' => 'x'])['source']);
        $this->assertSame('whatsapp', RecordVisit::source('wa.me', ['ref' => 'pro'])['source']);
        $this->assertSame('pro', RecordVisit::source('wa.me', ['ref' => 'pro'])['campaign']);
        $this->assertSame('email', RecordVisit::source(null, ['utm_source' => 'newsletter'])['source']);
        $this->assertSame('direct', RecordVisit::source(null, [])['source']);
        $this->assertTrue(RecordVisit::source(null, ['utm_source' => 'google', 'utm_medium' => 'cpc'])['paid']);
        $this->assertFalse(RecordVisit::source('www.google.com', [])['paid']);
    }

    public function test_the_second_visit_of_a_visitor_is_its_second(): void
    {
        $this->record($this->body('sessionaaaa1', [['id' => 'step0001', 'kind' => 'view']]));
        $this->record($this->body('sessionbbbb2', [['id' => 'step0001', 'kind' => 'view']]));

        $this->assertSame([1, 2], $this->inShop(fn () => AnalyticsVisit::query()->orderBy('id')->pluck('visit_number')->all()));
    }

    public function test_a_purchase_joins_the_visitors_visit(): void
    {
        $this->record($this->body('sessionaaaa1', [['id' => 'step0001', 'kind' => 'view'], ['id' => 'step0002', 'kind' => 'cart', 'item' => '9']]));

        app(RecordOrder::class)->handle($this->connection, [
            'order_ref' => hash('sha256', 'A-1'), 'total' => 472, 'currency' => 'ILS', 'ordered_at' => now()->timestamp, 'vid' => 'anon-abcdefghijklmnopqrstu1',
            'items' => [['product_id' => '9', 'quantity' => 1, 'total' => 472]],
        ]);

        $visit = $this->inShop(fn () => AnalyticsVisit::query()->sole());
        $this->assertTrue($visit->has('order'));
        $this->assertEquals(472.0, (float) $visit->order_total);
        $this->assertSame('order', $this->inShop(fn () => AnalyticsVisitStep::query()->orderByDesc('at')->orderByDesc('id')->value('kind')));
    }

    public function test_the_screen_filters_and_opens_a_visit_with_its_steps_in_order(): void
    {
        $this->record($this->body('sessionaaaa1', [
            ['id' => 'step0001', 'kind' => 'view', 'type' => 'home', 'ts' => now()->subMinutes(5)->getTimestampMs()],
            ['id' => 'step0002', 'kind' => 'search', 'label' => 'לביד 17', 'n' => 8, 'ts' => now()->subMinutes(4)->getTimestampMs()],
            ['id' => 'step0003', 'kind' => 'ask', 'label' => 'איזה עובי מתאים למדפים?', 'ts' => now()->subMinute()->getTimestampMs()],
        ], ['landing' => '/', 'ref' => 'https://www.google.com/', 'params' => ['utm_campaign' => 'mdf-oct']]));
        $this->record($this->body('sessionbbbb2', [['id' => 'step0001', 'kind' => 'view', 'type' => 'product']], [], 'anon-zzzzzzzzzzzzzzzzzzzz99', '31.154.22.180'));

        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop, ShopRole::Owner);
        $this->actingAs($merchant);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel(User::MERCHANT_PANEL));
        Filament::setTenant($this->shop);
        // What the panel's middleware does on every request.
        app(TenantContext::class)->set($this->shop->id);
        $first = $this->inShop(fn (): int => AnalyticsVisit::query()->where('session_id', 'sessionaaaa1')->value('id'));

        Livewire::test(ShopSessions::class)
            ->assertSee('סשנים')
            ->assertSee('חיפש')
            ->assertSee('״לביד 17״')
            ->set('q', 'לביד')
            ->assertSee('77.127.44.9')
            ->assertDontSee('31.154.22.180')
            ->call('clear', 'all')
            ->call('toggle', 'param', 'mdf-oct')
            ->assertDontSee('31.154.22.180')
            ->call('clear', 'all')
            ->call('toggle', 'has', 'ask')
            ->assertSee('היה: שאלה לעוזר')
            ->assertDontSee('31.154.22.180')
            ->call('open', $first)
            ->tap(fn ($c) => $this->assertInOrder($c->html(), ['נחת בדף הבית', 'חיפש ״לביד 17״', '8 תוצאות', '3 דקות בלי פעולה', 'שאל: ״איזה עובי מתאים למדפים?״']))
            ->assertSee('utm_campaign=mdf-oct')
            ->call('narrowTo', 'ip')
            ->assertSet('ip', '77.127.44.9')
            ->assertSet('session', null);
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, mixed>  $first
     */
    private function body(string $sid, array $steps, array $first = [], string $vid = 'anon-abcdefghijklmnopqrstu1', string $ip = '77.127.44.9'): array
    {
        return ['ip' => $ip, 'json' => json_encode(['v' => 1, 'sid' => $sid, 'vid' => $vid, 'first' => $first ?: null, 'steps' => array_map(fn (array $s): array => ['ts' => now()->getTimestampMs(), ...$s], $steps)], JSON_UNESCAPED_UNICODE)];
    }

    /** @param array{ip: string, json: string} $body */
    private function record(array $body): void
    {
        $request = Request::create('/api/v1/visit/x', 'POST', server: [
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1',
            'REMOTE_ADDR' => $body['ip'],
        ], content: $body['json']);

        $result = app(RecordVisit::class)->handle($this->connection, $request, $body['json']);
        $this->assertSame([], $result['problems']);
    }

    /** @param list<string> $texts */
    private function assertInOrder(string $html, array $texts): void
    {
        $at = 0;
        foreach ($texts as $text) {
            $found = mb_strpos($html, $text, $at);
            $this->assertNotFalse($found, "after the one before: {$text}");
            $at = (int) $found + 1;
        }
    }

    private function inShop(callable $callback): mixed
    {
        return app(TenantContext::class)->run($this->shop->id, $callback);
    }
}
