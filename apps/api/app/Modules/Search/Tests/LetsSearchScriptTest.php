<?php

namespace App\Modules\Search\Tests;

use App\Modules\Search\Http\Controllers\LetsSearchScriptController;
use Tests\TestCase;

/**
 * GET /api/v1/search/lets-search.js (and the old let-agents-search.js) is a small loader, fresh for five minutes, that adds
 * the drawer from an address named by its content, kept by the browser for a year (App Store review, 19.11: 238 KB
 * downloaded again every five minutes). A deploy still reaches every store within minutes.
 */
final class LetsSearchScriptTest extends TestCase
{
    public function test_the_address_stores_load_is_a_small_loader_naming_the_drawer_by_its_content(): void
    {
        $hash = LetsSearchScriptController::hash();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{12}$/', $hash);

        // Stores on older plugins and the Shopify embed still print the old address: the same loader.
        foreach (['/api/v1/search/lets-search.js', '/api/v1/search/let-agents-search.js'] as $address) {
            $response = $this->get($address)
                ->assertOk()
                ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
                ->assertHeader('X-Content-Type-Options', 'nosniff');
            $loader = (string) $response->getContent();

            $this->assertLessThan(1024, strlen($loader), 'a few hundred bytes, not the drawer');
            $this->assertStringContainsString('lets-search.'.$hash.'.js', $loader, 'beside itself, with the same query');
            $this->assertStringContainsString('currentScript', $loader);
            $this->assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'), 'a deploy reaches stores within minutes');

            $etag = (string) $response->headers->get('ETag');
            $this->assertSame('"'.substr(hash('sha256', $loader), 0, 20).'"', $etag);
            $this->get($address, ['If-None-Match' => $etag])->assertStatus(304);
            $this->get($address, ['If-None-Match' => '"other"'])->assertOk();
        }
    }

    public function test_the_drawer_under_its_hash_is_kept_for_a_year_and_an_older_hash_gets_todays_file_briefly(): void
    {
        $drawer = (string) file_get_contents(LetsSearchScriptController::PATH);

        $file = $this->get('/api/v1/search/lets-search.'.LetsSearchScriptController::hash().'.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8');
        $this->assertSame($drawer, $file->getContent());
        // it reads both the new settings and the old plugins' context
        $this->assertStringContainsString('LetsSearchConfig', (string) $file->getContent());
        $this->assertStringContainsString('LetAgentsSearchContext', (string) $file->getContent());
        $this->assertStringContainsString('max-age=31536000', (string) $file->headers->get('Cache-Control'));
        $this->assertStringContainsString('immutable', (string) $file->headers->get('Cache-Control'));

        $old = $this->get('/api/v1/search/lets-search.000000000000.js')->assertOk();
        $this->assertSame($drawer, $old->getContent(), 'a loader still cached from before a deploy gets today\'s drawer');
        $this->assertStringNotContainsString('immutable', (string) $old->headers->get('Cache-Control'), 'never kept for a year under a name that is not its own');
    }
}
