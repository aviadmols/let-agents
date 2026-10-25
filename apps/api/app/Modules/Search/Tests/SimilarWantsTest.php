<?php

namespace App\Modules\Search\Tests;

use App\Modules\Search\Support\SimilarWants;
use PHPUnit\Framework\TestCase;

/** A question on a product's page asks of its alike what else it says: words the store says, and the price beside its own. */
final class SimilarWantsTest extends TestCase
{
    private const RECORDS = [
        'p:1' => ['title' => 'טבעת וינטג\' משובצת אמטיסט', 'kw' => 'זהב צהוב', 'pr' => 2000, 'kd' => ['טבעת']],
        'p:2' => ['title' => 'טבעת וינטג\' משובצת אמטיסט ויהלומים', 'kw' => '', 'pr' => 2400],
        'p:3' => ['title' => 'טבעת משובצת יהלומים', 'kw' => 'זהב לבן', 'pr' => 5200],
        'p:4' => ['title' => 'טבעת משובצת רובי', 'kw' => '', 'pr' => 6000],
        'p:5' => ['title' => 'טבעת יהלום סוליטר', 'kw' => '', 'pr' => 900],
        'p:6' => ['title' => 'Diamond halo ring', 'kw' => 'white gold', 'pr' => 4100],
    ];

    public function test_a_diamond_and_much_more_expensive_keeps_only_those(): void
    {
        $wants = SimilarWants::read('יש טבעת דומה עם יהלום והרבה יותר יקרה?', self::RECORDS, self::RECORDS['p:1']);

        $this->assertSame(['יהלום'], $wants['words'], 'the ring is its kind, "דומה" the asking, "והרבה" no wish');
        $this->assertSame('up', $wants['price']);
        $this->assertTrue($wants['much']);

        $kept = SimilarWants::apply([self::RECORDS['p:2'], self::RECORDS['p:3'], self::RECORDS['p:4'], self::RECORDS['p:5']], $wants, self::RECORDS['p:1']);
        $this->assertSame(['טבעת משובצת יהלומים'], array_column($kept, 'title'), 'a diamond at 2400 is not much dearer; a ruby has none; 900 is cheaper');
    }

    public function test_cheaper_in_english_and_a_word_no_product_says_is_no_wish(): void
    {
        $wants = SimilarWants::read('any similar ones please, but cheaper?', self::RECORDS, self::RECORDS['p:3']);

        $this->assertSame([], $wants['words']);
        $this->assertSame('down', $wants['price']);
        $this->assertFalse($wants['much']);
        $kept = SimilarWants::apply(array_values(self::RECORDS), $wants, self::RECORDS['p:3']);
        $this->assertSame([2000, 2400, 900, 4100], array_column($kept, 'pr'));
    }

    public function test_only_asking_for_its_alike_or_another_colour_wants_nothing_else(): void
    {
        foreach (['יש טבעות דומות?', 'יש טבעות דומות עם אבן בצבע אחר?', 'similar ones in another colour?'] as $asked) {
            $this->assertFalse(SimilarWants::any(SimilarWants::read($asked, self::RECORDS, self::RECORDS['p:1'])), $asked);
        }
    }
}
