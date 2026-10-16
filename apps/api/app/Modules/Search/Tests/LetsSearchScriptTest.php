<?php

namespace App\Modules\Search\Tests;

use App\Modules\Search\Http\Controllers\LetsSearchScriptController;
use Tests\TestCase;

/**
 * GET /api/v1/search/lets-search.js serves the built drawer from disk, cached for five minutes,
 * with a version tag a browser can revalidate.
 */
final class LetsSearchScriptTest extends TestCase
{
    public function test_the_drawer_is_served_with_a_version_tag(): void
    {
        $response = $this->get('/api/v1/search/lets-search.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringContainsString('public', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(file_get_contents(LetsSearchScriptController::PATH), $response->getContent());
        $this->assertStringContainsString('LetsSearchConfig', (string) $response->getContent());

        $etag = (string) $response->headers->get('ETag');
        $this->assertSame('"'.substr(hash('sha256', (string) $response->getContent()), 0, 20).'"', $etag);

        $this->get('/api/v1/search/lets-search.js', ['If-None-Match' => $etag])->assertStatus(304);
        $this->get('/api/v1/search/lets-search.js', ['If-None-Match' => '"other"'])->assertOk();
    }

    public function test_the_old_search_scripts_address_serves_the_drawer(): void
    {
        // Stores on older plugins and the Shopify embed still print this address.
        $response = $this->get('/api/v1/search/let-agents-search.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringContainsString('max-age=300', (string) $response->headers->get('Cache-Control'));
        $this->assertSame(file_get_contents(LetsSearchScriptController::PATH), $response->getContent());
        // It reads the old context as its settings.
        $this->assertStringContainsString('LetAgentsSearchContext', (string) $response->getContent());

        $this->get('/api/v1/search/let-agents-search.js', ['If-None-Match' => (string) $response->headers->get('ETag')])->assertStatus(304);
    }
}
