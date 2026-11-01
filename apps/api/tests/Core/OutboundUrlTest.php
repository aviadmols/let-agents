<?php

namespace Tests\Core;

use App\Core\Http\OutboundUrl;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use RuntimeException;
use Tests\TestCase;

/**
 * An address a store, a shopper or a demo gave is fetched only on the public internet: never the platform's own network,
 * the cloud's metadata address or a loopback, however it is written, nor through a redirect, nor without end.
 */
final class OutboundUrlTest extends TestCase
{
    public function test_a_private_or_internal_address_is_never_fetched_however_it_is_written(): void
    {
        foreach ([
            'http://127.0.0.1/', 'http://169.254.169.254/latest/meta-data/', 'http://10.0.0.5/', 'http://192.168.1.1/', 'http://172.20.0.1/',
            'http://100.64.0.1/', 'http://198.18.0.1/', 'http://0.0.0.0/', 'http://[::1]/', 'http://[fd12:3456::1]/', 'http://[fe80::1]/',
            'http://[::ffff:127.0.0.1]/', 'http://[64:ff9b::7f00:1]/', 'http://[2002:7f00:1::]/', 'http://localhost/', 'http://redis.railway.internal/',
            'http://api.internal/', 'http://printer.local/',
        ] as $url) {
            $this->assertSame('private', OutboundUrl::refusal($url), $url);
        }
    }

    public function test_only_http_on_its_own_ports_without_credentials(): void
    {
        $this->assertSame('not_http', OutboundUrl::refusal('file:///etc/passwd'));
        $this->assertSame('not_http', OutboundUrl::refusal('gopher://example.com/'));
        $this->assertSame('not_http', OutboundUrl::refusal('javascript:alert(1)'));
        $this->assertSame('port', OutboundUrl::refusal('http://93.184.216.34:2019/config/'), "Caddy's admin port");
        $this->assertSame('credentials', OutboundUrl::refusal('http://user:pass@93.184.216.34/'));
        $this->assertNull(OutboundUrl::refusal('https://93.184.216.34/'));
        $this->assertNull(OutboundUrl::refusal('https://[2606:2800:220:1:248:1893:25c8:1946]/'));
        $this->assertNull(OutboundUrl::request('http://169.254.169.254/'));
    }

    public function test_a_redirect_is_checked_again_and_a_body_has_a_cap(): void
    {
        $options = OutboundUrl::options('https://93.184.216.34/', 1000);
        $redirect = $options['allow_redirects']['on_redirect'];
        $redirect(new Request('GET', 'https://93.184.216.34/'), new Response(302), new Uri('https://93.184.216.34/other'));

        try {
            $redirect(new Request('GET', 'https://93.184.216.34/'), new Response(302), new Uri('http://169.254.169.254/'));
            $this->fail('a redirect to the metadata address was followed');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $progress = $options['progress'];
        $progress(0, 900);
        $this->expectException(RuntimeException::class);
        $progress(0, 1001);
    }
}
