<?php

namespace App\Modules\Connections\Tests;

use App\Core\Tenancy\MissingTenantContext;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Actions\TestStoreConnection;
use App\Modules\Connections\Enums\ConnectionStatus;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class TestStoreConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'rgt_abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUV';

    private StoreConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $shop = Shop::factory()->create();
        $this->connection = app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create([
            'shop_id' => $shop->id,
            'site_url' => 'https://guetaavigdor.test/',
            'access_token' => self::TOKEN,
        ]));
    }

    public function test_a_working_plugin_connects_and_the_site_info_is_saved(): void
    {
        Http::fake([
            'guetaavigdor.test/wp-json/let-agents/v1/status' => Http::response(['data' => $this->statusPayload()]),
        ]);

        $run = app(TestStoreConnection::class)->handle($this->connection);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-LetAgents-Token', self::TOKEN));

        $this->connection->refresh();
        $this->assertSame(ConnectionStatus::Connected, $this->connection->status);
        $this->assertSame('11.1.0', $this->connection->info('woocommerce.version'));
        $this->assertSame(1191, $this->connection->info('counts.products.publish'));
        $this->assertNotNull($this->connection->last_checked_at);

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertSame($this->connection->shop_id, $run->shop_id);
        $this->assertSame(1191, $run->output['published_products']);
        $this->assertStringContainsString('1,191', (string) $run->summary());
        $this->assertStringNotContainsString(self::TOKEN, json_encode($run->toArray()));
    }

    public function test_the_address_is_normalized_and_the_token_is_encrypted_at_rest(): void
    {
        $this->assertSame('https://guetaavigdor.test', $this->connection->site_url);
        $this->assertSame('rgt_abcdef', $this->connection->token_prefix);

        $stored = DB::table('store_connections')->where('id', $this->connection->id)->value('access_token');
        $this->assertNotSame(self::TOKEN, $stored);
        $this->assertStringNotContainsString('rgt_', $stored);
        $this->assertSame(self::TOKEN, $this->connection->fresh()->access_token);
    }

    public function test_sites_without_pretty_permalinks_are_reached_through_rest_route(): void
    {
        Http::fake([
            'guetaavigdor.test/wp-json/*' => Http::response('<html>Not found</html>', 404),
            'guetaavigdor.test/?rest_route=*' => Http::response(['data' => $this->statusPayload()]),
        ]);

        $run = app(TestStoreConnection::class)->handle($this->connection);

        $this->assertSame(RunStatus::Succeeded, $run->status);
        $this->assertStringContainsString('rest_route', $run->output['url']);
    }

    public function test_a_wordpress_answer_that_the_route_is_missing_is_not_retried(): void
    {
        Http::fake(['guetaavigdor.test/*' => Http::response(['code' => 'rest_no_route', 'message' => 'No route'], 404)]);

        $run = app(TestStoreConnection::class)->handle($this->connection);

        Http::assertSentCount(1);
        $this->assertSame('connections::runs.failures.plugin_missing', $run->summary_key);
    }

    /**
     * Plain data: a data provider runs before the application boots, so it cannot build HTTP fakes.
     *
     * @return array<string, array{0: array<string, string>|string, 1: int, 2: string}>
     */
    public static function failures(): array
    {
        return [
            'wrong token' => [['code' => 'let_agents_invalid_token'], 401, 'invalid_token'],
            'locked out' => [['code' => 'let_agents_rate_limited'], 429, 'locked_out'],
            'plugin not installed' => [['code' => 'rest_no_route'], 404, 'plugin_missing'],
            'woocommerce off' => [['code' => 'let_agents_woocommerce_inactive'], 503, 'woocommerce_inactive'],
            'server error' => ['oops', 500, 'http_error'],
            'something else answered' => [['hello' => 'world'], 200, 'unexpected_response'],
        ];
    }

    /** @param array<string, string>|string $body */
    #[DataProvider('failures')]
    public function test_each_failure_is_explained(array|string $body, int $status, string $code): void
    {
        Http::fake(['guetaavigdor.test/*' => Http::response($body, $status)]);

        $run = app(TestStoreConnection::class)->handle($this->connection);

        $this->assertSame(RunStatus::Failed, $run->status);
        $this->assertSame("connections::runs.failures.{$code}", $run->summary_key);
        $this->assertSame(ConnectionStatus::Failed, $this->connection->fresh()->status);
        $this->assertSame($code, $this->connection->fresh()->last_error_code);
    }

    public function test_an_unreachable_site_is_explained(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host'));

        $run = app(TestStoreConnection::class)->handle($this->connection);

        $this->assertSame('connections::runs.failures.unreachable', $run->summary_key);
        $this->assertStringContainsString('Could not resolve host', (string) $run->error);
    }

    public function test_a_new_token_resets_the_status_until_it_is_tested(): void
    {
        Http::fake(['guetaavigdor.test/*' => Http::response(['data' => $this->statusPayload()])]);
        app(TestStoreConnection::class)->handle($this->connection);
        $this->assertSame(ConnectionStatus::Connected, $this->connection->fresh()->status);

        app(TenantContext::class)->runUnscoped(fn () => $this->connection->fresh()->update(['access_token' => 'rgt_'.str_repeat('z', 48)]));

        $this->assertSame(ConnectionStatus::Untested, $this->connection->fresh()->status);
    }

    public function test_connections_belong_to_one_shop(): void
    {
        $this->expectException(MissingTenantContext::class);

        StoreConnection::query()->count();
    }

    /** @return array<string, mixed> */
    private function statusPayload(): array
    {
        return [
            'plugin' => ['version' => '0.1.0', 'token' => ['prefix' => 'rgt_abcdef']],
            'site' => ['name' => 'גואטה אביגדור', 'wordpress' => '7.1', 'locale' => 'he_IL'],
            'woocommerce' => ['active' => true, 'version' => '11.1.0', 'currency' => 'ILS'],
            'counts' => ['products' => ['publish' => 1191, 'draft' => 3], 'variations' => 40, 'product_categories' => 116],
        ];
    }
}
