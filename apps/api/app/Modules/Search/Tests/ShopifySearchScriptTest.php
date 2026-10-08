<?php

namespace App\Modules\Search\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The storefront script on Shopify: the product handle it reads live prices by, prices written the
 * way the shop writes them, and the variant it shows and puts in the cart.
 */
final class ShopifySearchScriptTest extends TestCase
{
    private const SCRIPT = __DIR__.'/../resources/search/let-agents-search.js';

    /** Runs a node expression against the script's exports, as `e`, and returns its JSON value. */
    private static function node(string $expression): mixed
    {
        $node = (new ExecutableFinder)->find('node');

        if ($node === null) {
            self::markTestSkipped('Node is not installed here; CI runs this with Node.');
        }

        $harness = 'const e=require('.json_encode(realpath(self::SCRIPT)).');'
            .'process.stdout.write(JSON.stringify('.$expression.'));';

        $process = new Process([$node, '-e', $harness]);
        $process->mustRun();

        return json_decode($process->getOutput(), true);
    }

    public function test_the_handle_comes_from_any_product_link(): void
    {
        $urls = [
            'https://shop.example/products/oak-deck',
            '/en/products/oak-deck?variant=42',
            'https://shop.example/collections/decks/products/oak-deck#reviews',
            '/products/%D7%93%D7%A7-%D7%90%D7%9C%D7%95%D7%9F',
            '/pages/about',
            '',
        ];

        $this->assertSame(
            ['oak-deck', 'oak-deck', 'oak-deck', 'דק-אלון', null, null],
            self::node(json_encode($urls, JSON_UNESCAPED_UNICODE).'.map(u=>e.handleFromUrl(u))'),
        );
    }

    public function test_prices_are_written_in_the_shops_money_format(): void
    {
        $cases = [
            [123456, '${{amount}}', '$1,234.56'],
            [123456, '{{amount_no_decimals}} ₪', '1,235 ₪'],
            [123456, '{{ amount_with_comma_separator }} €', '1.234,56 €'],
            [123456, '{{amount_no_decimals_with_comma_separator}} kr', '1.235 kr'],
            [123456, 'CHF {{amount_with_apostrophe_separator}}', "CHF 1'234.56"],
            [990, '<span class="money">&#8362;{{amount}}</span>', '₪9.90'],
            [5000, '{{unknown_style}}', '50.00'],
        ];
        $expression = json_encode($cases, JSON_UNESCAPED_UNICODE).'.map(c=>e.formatMoney(c[0],c[1],"ILS","he"))';

        $this->assertSame(array_column($cases, 2), self::node($expression));
    }

    public function test_without_a_money_format_the_currency_is_written_by_the_browser(): void
    {
        $this->assertSame('$12.50', self::node('e.formatMoney(1250,"","USD","en")'));
        $this->assertSame('', self::node('e.formatMoney(null,"{{amount}}","USD","en")'));
    }

    public function test_the_first_variant_in_stock_is_shown_and_bought(): void
    {
        $product = ['variants' => [
            ['id' => 1, 'available' => false, 'price' => 500, 'compare_at_price' => null],
            ['id' => 2, 'available' => true, 'price' => 900, 'compare_at_price' => 1200],
            ['id' => 3, 'available' => true, 'price' => 800, 'compare_at_price' => null],
        ]];

        $this->assertSame([
            'is_in_stock' => true,
            'on_sale' => true,
            'prices' => ['price' => 900, 'regular_price' => 1200, 'currency_minor_unit' => 2],
            'variant' => 2,
            'variants' => 3,
        ], self::node('e.fromShopifyProduct('.json_encode($product).')'));
    }

    public function test_a_product_out_of_stock_shows_its_cheapest_price(): void
    {
        $product = ['variants' => [
            ['id' => 1, 'available' => false, 'price' => 900, 'compare_at_price' => 800],
            ['id' => 2, 'available' => false, 'price' => 700, 'compare_at_price' => null],
        ]];

        $live = self::node('e.fromShopifyProduct('.json_encode($product).')');

        $this->assertFalse($live['is_in_stock']);
        $this->assertSame(2, $live['variant']);
        $this->assertFalse($live['on_sale']);
        $this->assertNull(self::node('e.fromShopifyProduct({variants:[]})'));
    }
}
