<?php

namespace App\Modules\Catalog\Tests;

use App\Modules\Catalog\Contracts\StorefrontLock;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use Tests\TestCase;

/**
 * A storefront closed to visitors answers every address with one lock page: Shopify's password
 * page, WordPress's maintenance page, a coming-soon plugin. Told apart in code from a page of the
 * store, so nothing of it is ever read as the store's.
 */
final class StorefrontLockTest extends TestCase
{
    /** Shopify's password page as Dawn renders it on a development store. */
    public const SHOPIFY_PASSWORD = <<<'HTML'
        <!doctype html>
        <html class="no-js" lang="en">
        <head>
          <meta charset="utf-8">
          <title>Lets Shop</title>
          <link href="//lets-shop.myshopify.com/cdn/shop/t/1/assets/base.css?v=1" rel="stylesheet" type="text/css" media="all" />
          <script>window.Shopify = window.Shopify || {}; Shopify.shop = "lets-shop.myshopify.com";</script>
        </head>
        <body class="password">
          <div class="password-header"><h1 class="password-header__title">Lets Shop</h1></div>
          <main id="MainContent" class="password-main">
            <section class="newsletter">
              <h2>Opening soon</h2>
              <p>This store is password protected. Use the password to enter the store.</p>
              <form method="post" action="/password" id="login_form" accept-charset="UTF-8" class="password-form"><input type="hidden" name="form_type" value="storefront_password" /><input type="hidden" name="utf8" value="✓" />
                <label for="Password">Enter store password</label>
                <input type="password" name="password" id="Password" autocomplete="current-password" placeholder="Your password">
                <button name="commit">Enter</button>
              </form>
              <p>Are you the store owner? <a href="/admin">Log in here</a></p>
            </section>
          </main>
          <footer class="password-footer"><p>This shop will be powered by <a href="https://www.shopify.com">Shopify</a></p></footer>
        </body>
        </html>
        HTML;

    /** A product page with a login drawer in its header and a password in its words: a page of the store. */
    private const PRODUCT = <<<'HTML'
        <!doctype html>
        <html lang="he"><head><title>טבעת זהב | Lets Shop</title></head>
        <body class="template-product">
          <header>
            <a href="/account/login">התחברות</a>
            <form method="post" action="/account/login" id="customer_login"><input type="hidden" name="form_type" value="customer_login" />
              <input type="email" name="customer[email]"><input type="password" name="customer[password]"><button>כניסה</button>
              <a href="/account/login#recover">שכחתם את הסיסמה?</a>
            </form>
          </header>
          <main>
            <h1>טבעת זהב 14K</h1>
            <p>טבעת זהב צהוב 14 קראט, בעבודת יד בסטודיו שלנו בתל אביב. אפשר להזמין חריטה אישית.</p>
            <form action="/cart/add" method="post"><input type="hidden" name="form_type" value="product" /><select name="id"><option>52</option><option>54</option></select><button>הוספה לסל</button></form>
            <p>החזר כספי מלא תוך 14 יום. משלוח חינם בקנייה מעל 300 ש״ח.</p>
            <p>The gift card arrives by email with a code, not a password, and never expires.</p>
          </main>
          <footer><p>Powered by Shopify</p></footer>
        </body></html>
        HTML;

    public function test_shopifys_password_page_is_a_lock_and_a_product_page_is_not(): void
    {
        $this->assertSame(StorefrontLock::PASSWORD, StorefrontLock::page(self::SHOPIFY_PASSWORD));
        $this->assertSame(StorefrontLock::PASSWORD, StorefrontLock::of(new Response(new Psr7Response(200, ['Content-Type' => 'text/html'], self::SHOPIFY_PASSWORD))));
        $this->assertTrue(StorefrontLock::wide(StorefrontLock::PASSWORD), 'the whole storefront is closed');

        $this->assertNull(StorefrontLock::page(self::PRODUCT), 'a login form, "Powered by Shopify" and the word password are a page of the store');
        $this->assertNull(StorefrontLock::of(new Response(new Psr7Response(200, [], self::PRODUCT))));
        $this->assertNull(StorefrontLock::of(null), 'no answer is no lock');
    }

    public function test_the_password_page_is_known_by_its_address_its_form_or_its_words_in_any_theme(): void
    {
        // Every address of a locked store is sent to /password.
        $this->assertSame(StorefrontLock::PASSWORD, StorefrontLock::of(new Response(new Psr7Response(302, ['Location' => 'https://lets-shop.myshopify.com/password']))));
        $this->assertSame(StorefrontLock::PASSWORD, StorefrontLock::page('', 'https://lets-shop.test/he/password'));
        $this->assertNull(StorefrontLock::of(new Response(new Psr7Response(302, ['Location' => 'https://lets-shop.test/collections/rings']))));

        // A theme with neither the form's marks nor its template's class: the password field and its words.
        $bare = '<html><body><div class="gate"><h1>Lets Shop</h1><p>Enter store password</p><input name="password" type="password"><button>Enter</button></div></body></html>';
        $this->assertSame(StorefrontLock::PASSWORD, StorefrontLock::page($bare));

        // In Hebrew.
        $hebrew = '<html><body><main><h1>Lets Shop</h1><p>החנות הזו מוגנת בסיסמה.</p><p>הזינו את סיסמת החנות</p><input type="password" name="password"></main></body></html>';
        $this->assertSame(StorefrontLock::PASSWORD, StorefrontLock::page($hebrew));
    }

    public function test_maintenance_and_coming_soon_pages_are_locks_and_one_protected_post_closes_only_itself(): void
    {
        $wordpress = '<!DOCTYPE html><html><head><title>Maintenance</title></head><body><p>Briefly unavailable for scheduled maintenance. Check back in a minute.</p></body></html>';
        $this->assertSame(StorefrontLock::MAINTENANCE, StorefrontLock::of(new Response(new Psr7Response(503, ['Retry-After' => '600'], $wordpress))));

        $soon = '<html><head><meta name="generator" content="SeedProd"></head><body class="seedprod-coming-soon"><h1>Coming Soon</h1><p>We are working on something new. Sign up to hear when we open.</p></body></html>';
        $this->assertSame(StorefrontLock::COMING_SOON, StorefrontLock::page($soon, null, 200));

        $gate = '<html><body><div class="age-gate"><h2>Are you over 18?</h2><form><button>Yes</button><button>No</button></form></div></body></html>';
        $this->assertSame(StorefrontLock::AGE_GATE, StorefrontLock::page($gate));
        $popup = '<html><body><div class="age-gate"><h2>Are you over 18?</h2><button>Yes</button></div><main><h1>Merlot 2021</h1><form action="/cart/add"><button class="add-to-cart">Add to cart</button></form></main></body></html>';
        $this->assertNull(StorefrontLock::page($popup), 'an age popup over a product page leaves the page there');

        $post = '<html><body><main><h1>Wholesale prices</h1><form action="https://shop.test/wp-login.php?action=postpass" class="post-password-form" method="post"><p>This content is password protected. To view it please enter your password below:</p><input name="post_password" type="password"></form></main></body></html>';
        $this->assertSame(StorefrontLock::PROTECTED_PAGE, StorefrontLock::page($post));
        $this->assertFalse(StorefrontLock::wide(StorefrontLock::PROTECTED_PAGE), 'one page, not the store');
    }

    public function test_text_kept_from_a_lock_page_is_known_and_the_stores_own_is_not(): void
    {
        foreach (['Enter store password', 'This store is password protected. Use the password to enter the store.', 'Are you the store owner? Log in here', 'החנות הזו מוגנת בסיסמה'] as $line) {
            $this->assertTrue(StorefrontLock::text($line), $line);
        }

        foreach (['החזר כספי מלא תוך 14 יום', 'Free shipping on orders over ₪300', 'Your account password is never shared with anyone.'] as $line) {
            $this->assertFalse(StorefrontLock::text($line), $line);
        }
    }

    public function test_a_store_seen_locked_is_remembered_until_a_page_is_read_open(): void
    {
        $this->assertNull(StorefrontLock::state('shop-1'));

        StorefrontLock::seen('shop-1', StorefrontLock::PASSWORD);
        $this->assertSame(StorefrontLock::PASSWORD, StorefrontLock::state('shop-1')['kind']);
        $this->assertNull(StorefrontLock::state('shop-2'), 'per shop');

        StorefrontLock::open('shop-1');
        $this->assertNull(StorefrontLock::state('shop-1'));
    }
}
