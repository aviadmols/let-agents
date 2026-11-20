<?php

namespace App\Modules\Search\Tests;

use App\Modules\Search\Support\SameNameFilters;
use PHPUnit\Framework\TestCase;

/**
 * Two filters of one name are one on the screen (Shilav, 20.11: "מידה" twice, the variants' and the vocabulary's): the
 * fuller stays, the other's values are found in it or added, each product keeps reaching what it reached.
 */
final class SameNameFiltersTest extends TestCase
{
    public function test_two_filters_of_one_name_become_one_and_no_product_is_lost(): void
    {
        $facets = [
            ['k' => 'type', 'l' => 'סוג מוצר', 'v' => [['k' => 'shirt', 'l' => 'חולצה']]],
            ['k' => 'clothing_size', 'l' => 'מידה', 'v' => [['k' => 'nb', 'l' => 'NB'], ['k' => 'm0_3', 'l' => '0-3m'], ['k' => 'y8', 'l' => '8Y']]],
            ['k' => 'variant_size', 'l' => 'מידה', 'vo' => [2, 0, 1], 'v' => [['k' => '03M', 'l' => '0-3m'], ['k' => '7Y', 'l' => '7Y'], ['k' => 'NB', 'l' => 'NB'], ['k' => '23Y', 'l' => '2-3Y']]],
        ];
        $items = [
            ['id' => 'p:1', 'f' => [0, 1000, 2002]],   // NB by both
            ['id' => 'p:2', 'f' => [0, 1001]],         // 0-3m by its words alone
            ['id' => 'p:3', 'f' => [1002]],            // a size the variants do not have
            ['id' => 'p:4', 'f' => [2001]],
            ['id' => 'c:1'],
        ];

        [$merged, $records] = SameNameFilters::merged($facets, $items);

        $this->assertSame(1, $merged[1]['hide'], 'the vocabulary\'s leaves the screen');
        $this->assertArrayNotHasKey('hide', $merged[2]);
        $this->assertSame(['0-3m', '7Y', 'NB', '2-3Y', '8Y'], array_column($merged[2]['v'], 'l'), 'a size it had not is added, last');
        $this->assertSame([2, 0, 1, 4], $merged[2]['vo']);
        $this->assertSame([0, 1000, 2002], $records[0]['f']);
        $this->assertSame([0, 1001, 2000], $records[1]['f'], 'read from its words, now under the variants\' 0-3m too');
        $this->assertSame([1002, 2004], $records[2]['f']);
        $this->assertSame([2001], $records[3]['f']);
        $this->assertSame(['id' => 'c:1'], $records[4]);
    }

    public function test_a_brand_is_found_under_its_fuller_name_and_filters_of_other_names_stay(): void
    {
        $facets = [
            ['k' => 'brand', 'l' => 'מותג', 'v' => [['k' => 'bibs', 'l' => 'ביבס'], ['k' => 'cybex', 'l' => 'סייבקס']]],
            ['k' => 'color', 'l' => 'צבע', 'v' => [['k' => 'pink', 'l' => 'ורוד']]],
            ['k' => 'brand', 'l' => 'מותג', 'v' => [['k' => 'brand:ביבס bibs', 'l' => 'ביבס Bibs'], ['k' => 'brand:bobux', 'l' => 'BOBUX'], ['k' => 'brand:doona', 'l' => 'דונה Doona']]],
        ];

        [$merged, $records] = SameNameFilters::merged($facets, [['id' => 'p:1', 'f' => [0, 1000]], ['id' => 'p:2', 'f' => [1]]]);

        $this->assertSame(1, $merged[0]['hide']);
        $this->assertArrayNotHasKey('hide', $merged[1]);
        $this->assertSame(['ביבס Bibs', 'BOBUX', 'דונה Doona', 'סייבקס'], array_column($merged[2]['v'], 'l'));
        $this->assertSame([0, 1000, 2000], $records[0]['f']);
        $this->assertSame([1, 2003], $records[1]['f']);

        // a brand in Hebrew letters is the same brand in Latin ones (Gueta, 20.11: "סטנלי" beside "Stanley")
        [$tools] = SameNameFilters::merged([
            ['k' => 'brand', 'l' => 'מותג', 'v' => [['k' => 'a', 'l' => 'סטנלי'], ['k' => 'b', 'l' => 'מקיטה'], ['k' => 'c', 'l' => 'קרג'], ['k' => 'd', 'l' => 'פשוט ירוק']]],
            ['k' => 'brand', 'l' => 'מותג', 'v' => [['k' => 'e', 'l' => 'Stanley'], ['k' => 'f', 'l' => 'Makita'], ['k' => 'g', 'l' => 'Kreg'], ['k' => 'h', 'l' => 'Einhell'], ['k' => 'i', 'l' => 'פשוט ירוק']]],
        ], []);
        $this->assertSame(['Stanley', 'Makita', 'Kreg', 'Einhell', 'פשוט ירוק'], array_column($tools[1]['v'], 'l'));

        // one value under one name inside a filter: "מבריק" twice (Gueta's gloss and glossy) shows once, nothing lost
        [$finish, $held] = SameNameFilters::merged([['k' => 'finish', 'l' => 'גימור', 'v' => [['k' => 'matte', 'l' => 'מט'], ['k' => 'gloss', 'l' => 'מבריק'], ['k' => 'glossy', 'l' => 'מבריק']]]],
            [['id' => 'p:1', 'f' => [2]], ['id' => 'p:2', 'f' => [1]]]);
        $this->assertSame([2], $finish[0]['vh']);
        $this->assertSame([[2, 1], [1]], array_column($held, 'f'));

        $alone = [['k' => 'a', 'l' => 'מידה', 'v' => [['k' => 'x', 'l' => 'S']]], ['k' => 'b', 'l' => 'צבע', 'v' => [['k' => 'y', 'l' => 'S']]]];
        $this->assertSame([$alone, []], SameNameFilters::merged($alone, []));
    }
}
