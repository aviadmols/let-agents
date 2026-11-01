<?php

namespace Tests\Core;

use App\Core\Media\Picture;
use App\Core\Text\CsvCell;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * 1.11: what every response says about itself, and the small guards the platform's files and pictures pass through.
 */
final class SecurityHeadersTest extends TestCase
{
    public function test_a_page_is_framed_by_our_own_pages_only_unless_it_said_who_may(): void
    {
        Route::get('/_t/page', fn () => response('<p>hi</p>'));
        Route::get('/_t/framed', fn () => response('<p>hi</p>')->header('Content-Security-Policy', 'frame-ancestors https://admin.shopify.com;'));
        Route::get('/_t/json', fn () => response()->json(['ok' => true]));

        $page = $this->get('/_t/page')->assertOk();
        $this->assertSame("frame-ancestors 'self';", $page->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $page->headers->get('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $page->headers->get('Referrer-Policy'));

        $this->assertSame('frame-ancestors https://admin.shopify.com;', $this->get('/_t/framed')->headers->get('Content-Security-Policy'), 'a screen that names who may frame it keeps its own');
        $this->assertNull($this->get('/_t/page?shop=a.myshopify.com&host=abc')->headers->get('Content-Security-Policy'), 'Shopify opening the app embedded');
        $this->assertNull($this->getJson('/_t/json')->headers->get('Content-Security-Policy'));
        $this->assertNotNull($this->get('https://localhost/_t/page')->headers->get('Strict-Transport-Security'));
    }

    public function test_a_cell_that_reads_like_a_formula_is_kept_as_text(): void
    {
        $this->assertSame(["'=HYPERLINK(\"x\")", "'@SUM(A1)", "'+cmd", 'מדף', '-5', '+972501234567', 12, null], CsvCell::row(['=HYPERLINK("x")', '@SUM(A1)', '+cmd', 'מדף', '-5', '+972501234567', 12, null]));
    }

    public function test_a_picture_that_says_it_is_enormous_is_never_opened(): void
    {
        $small = imagecreatetruecolor(4, 3);
        ob_start();
        imagepng($small);
        $png = (string) ob_get_clean();

        $this->assertNotFalse(Picture::decode($png));
        $this->assertFalse(Picture::decode($png, 11), 'more pixels than the cap');
        $this->assertFalse(Picture::decode('not a picture'));
    }
}
