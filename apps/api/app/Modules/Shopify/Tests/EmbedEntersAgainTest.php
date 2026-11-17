<?php

namespace App\Modules\Shopify\Tests;

use Tests\TestCase;

/**
 * Inside the Shopify admin every store's panel shares one sign-in in a browser. A second store opened there takes
 * it, and the first store's next request is refused (403 by its token, 404 by its cookie): a white "404" covered
 * the screen in the middle of onboarding's scan (App Store review, 17.11). The page then goes in again with its own
 * token, as it does for an expired page, a few times a minute at most, so a page truly not there still says so.
 */
final class EmbedEntersAgainTest extends TestCase
{
    public function test_a_request_refused_for_another_stores_sign_in_goes_in_again_and_never_without_end(): void
    {
        $page = (string) file_get_contents(dirname(__DIR__).'/resources/views/embed.blade.php');

        $this->assertStringContainsString('failed.status === 419 || failed.status === 401', $page, 'an expired page, as before');
        $this->assertStringContainsString('(failed.status === 404 || failed.status === 403) && letAgentsMayEnterAgain()', $page);
        $this->assertStringContainsString('if (times.length >= 3) { return false; }', $page, 'three times a minute at most');
        $this->assertStringContainsString('now - t < 60000', $page);
        // where the tab keeps nothing, it is not tried: the error shows as it did
        $this->assertMatchesRegularExpression('/function letAgentsMayEnterAgain\(\) \{\s+try \{.*?\} catch \(e\) \{\s+return false;\s+\}\s+\}/s', $page);
    }
}
