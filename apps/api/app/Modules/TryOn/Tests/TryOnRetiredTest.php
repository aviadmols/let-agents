<?php

namespace App\Modules\TryOn\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Connections\Support\SiteKeys;
use App\Modules\Tenancy\Models\Shop;
use App\Modules\TryOn\Http\Controllers\TryOnScriptController;
use App\Modules\TryOn\Models\TryOnJob;
use App\Modules\TryOn\Models\TryOnPhoto;
use App\Modules\TryOn\Models\TryOnStep;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Try-On was removed from the storefront and the panels: the old script address still answers, with
 * a script that does nothing, every other address is gone, the flags are off, and what it kept stays
 * in the tables while its pictures are still deleted on their keeping date.
 */
final class TryOnRetiredTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'lat_tryon_retired_token_0123456789abcdef';

    public function test_the_old_script_answers_for_old_stores_and_nothing_else_of_try_on_is_served(): void
    {
        $shop = Shop::factory()->create(['domain' => 'www.store.test']);
        app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->create(['shop_id' => $shop->id, 'site_url' => 'https://www.store.test', 'access_token' => self::TOKEN]));
        $site = SiteKeys::site(self::TOKEN);

        $response = $this->get('/api/v1/try-on/let-agents-tryon.js')->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame(TryOnScriptController::SCRIPT, $response->getContent());
        $this->assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'));
        $this->get('/api/v1/try-on/let-agents-tryon.js', ['If-None-Match' => (string) $response->headers->get('ETag')])->assertStatus(304);

        // Even a shop with an old override turned on gets nothing.
        Features::override('try_on.enabled', true, $shop->id);
        $origin = ['Origin' => 'https://www.store.test'];
        $this->getJson("/api/v1/try-on/{$site}/config?product=1", $origin)->assertNotFound();
        $this->postJson("/api/v1/try-on/{$site}/photos", ['vid' => 'anon-0123456789abcdefghij'], $origin)->assertNotFound();
        $this->postJson("/api/v1/try-on/{$site}/tries", ['vid' => 'anon-0123456789abcdefghij'], $origin)->assertNotFound();
        $this->getJson("/api/v1/try-on/{$site}/tries/01HZZZZZZZZZZZZZZZZZZZZZZZ", $origin)->assertNotFound();
        $this->getJson('/api/v1/try-on/file?p=x')->assertNotFound();
    }

    public function test_it_is_off_by_default(): void
    {
        $shop = Shop::factory()->create();

        $this->assertFalse(Features::enabled('try_on.enabled', $shop->id));
        $this->assertTrue(Features::enabled('try_on.paused'));
    }

    public function test_pictures_are_still_deleted_after_their_days_and_the_rows_stay(): void
    {
        Storage::fake('local');
        $shop = Shop::factory()->create();
        $disk = Storage::disk('local');
        foreach (['photo', 'result', 'step', 'fresh'] as $name) {
            $disk->put("try-on/{$shop->id}/2026-10/{$name}.jpg", 'jpeg-bytes');
        }
        DB::table('try_on_files')->insert(['path' => "try-on/{$shop->id}/2026-10/photo.jpg", 'bytes' => base64_encode('jpeg-bytes'), 'created_at' => now()]);

        [$photo, $fresh, $job] = app(TenantContext::class)->run($shop->id, function () use ($shop): array {
            $base = ['shop_id' => $shop->id, 'visitor_hash' => str_repeat('a', 64), 'status' => 'ok'];
            $photo = TryOnPhoto::query()->create($base + ['photo_hash' => str_repeat('b', 64), 'path' => "try-on/{$shop->id}/2026-10/photo.jpg", 'expires_at' => now()->subDay()]);
            $fresh = TryOnPhoto::query()->create($base + ['photo_hash' => str_repeat('c', 64), 'path' => "try-on/{$shop->id}/2026-10/fresh.jpg", 'expires_at' => now()->addDays(30)]);
            $job = TryOnJob::query()->create(['shop_id' => $shop->id, 'photo_id' => $photo->id, 'visitor_hash' => str_repeat('a', 64), 'tryon_class' => 'ring', 'group' => 'jewelry',
                'fingerprint' => str_repeat('d', 64), 'status' => TryOnJob::READY, 'result_path' => "try-on/{$shop->id}/2026-10/result.jpg"]);
            TryOnStep::query()->create(['shop_id' => $shop->id, 'photo_id' => $photo->id, 'job_id' => $job->id, 'step' => 'generate', 'status' => 'ok', 'prompt' => 'Put the ring on.',
                'images' => ["try-on/{$shop->id}/2026-10/step.jpg"]]);

            return [$photo, $fresh, $job];
        });

        $this->artisan('try-on:prune')->assertSuccessful();

        foreach (['photo', 'result', 'step'] as $name) {
            $this->assertFalse($disk->exists("try-on/{$shop->id}/2026-10/{$name}.jpg"), "{$name} deleted");
        }
        $this->assertTrue($disk->exists("try-on/{$shop->id}/2026-10/fresh.jpg"), 'a photo inside its days stays');
        $this->assertSame(0, DB::table('try_on_files')->count());

        app(TenantContext::class)->run($shop->id, function () use ($photo, $fresh, $job): void {
            $this->assertNull(TryOnPhoto::query()->find($photo->id)->path);
            $this->assertNotNull(TryOnPhoto::query()->find($fresh->id)->path);
            $this->assertNull(TryOnJob::query()->find($job->id)->result_path);
            $step = TryOnStep::query()->where('job_id', $job->id)->sole();
            $this->assertNull($step->images);
            $this->assertSame('Put the ring on.', $step->prompt, 'what happened stays');
        });
    }
}
