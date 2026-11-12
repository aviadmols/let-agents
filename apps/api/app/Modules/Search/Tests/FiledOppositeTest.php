<?php

namespace App\Modules\Search\Tests;

use App\Modules\Search\Actions\BuildSearchIndex;
use App\Modules\Search\Actions\SearchCatalog;
use Tests\TestCase;

/**
 * A name the store files a product under that says the opposite of its own title is none of its words (Vans, 12.11:
 * "חולצה ארוכה Core Thermal" tagged "חולצות טי קצרות" was found for "חולצה קצרה כיס"). Any store, any such pair.
 */
final class FiledOppositeTest extends TestCase
{
    public function test_a_tag_that_says_the_opposite_of_the_title_is_not_its_word(): void
    {
        $this->assertTrue(BuildSearchIndex::contradicts('חולצה ארוכה Core Thermal', 'חולצות טי קצרות'));
        $this->assertTrue(BuildSearchIndex::contradicts('חולצת טי קצרה Trashy', 'חולצות ארוכות'));
        $this->assertTrue(BuildSearchIndex::contradicts('מכנס ג\'ינס קצר', 'מכנסיים ארוכים'));
        $this->assertTrue(BuildSearchIndex::contradicts('Long Sleeve Tee', 'Short sleeve'));
        $this->assertTrue(BuildSearchIndex::contradicts('נעל גבוהה Sk8-Hi', 'נעליים נמוכות'));
    }

    public function test_similar_items_put_the_opposite_last_and_read_the_colour_the_store_files(): void
    {
        $records = [
            ['id' => 'p:1', 'title' => 'חולצת טי ארוכה Left Chest'],
            ['id' => 'p:2', 'title' => 'חולצת טי קצרה Left Chest'],
            ['id' => 'p:3', 'title' => 'חולצת טי Pismo'],
        ];
        $this->assertSame(['p:2', 'p:3', 'p:1'], array_column(SearchCatalog::sameSideFirst($records, 'חולצת טי קצרה All\'s Well'), 'id'));
        $this->assertSame(['p:1', 'p:2', 'p:3'], array_column(SearchCatalog::sameSideFirst($records, 'חולצת טי All\'s Well'), 'id'), 'a title of neither side keeps the order');

        $facets = [['k' => 'variant_size', 'l' => 'מידה', 'v' => [['l' => 'M']]], ['k' => 'pa_color', 'l' => 'צבע', 'v' => [['l' => 'כחול'], ['l' => 'שחור']]]];
        $this->assertSame(['כחול'], SearchCatalog::filedColours(['f' => [0, 1000]], $facets));
        $this->assertSame([], SearchCatalog::filedColours(['f' => [0]], $facets));
    }

    public function test_a_tag_that_agrees_or_says_nothing_of_it_stays(): void
    {
        $this->assertFalse(BuildSearchIndex::contradicts('חולצה ארוכה Core Thermal', 'חולצות ארוכות'));
        $this->assertFalse(BuildSearchIndex::contradicts('חולצה ארוכה Core Thermal', 'חולצות גברים'));
        $this->assertFalse(BuildSearchIndex::contradicts('חולצה Core Thermal', 'חולצות טי קצרות'), 'the title says nothing of it');
        $this->assertFalse(BuildSearchIndex::contradicts('שמלה קצרה עם שרוול ארוך', 'שמלות קצרות'), 'the title says both');
        $this->assertFalse(BuildSearchIndex::contradicts('טבעת זהב', 'SALE'));
    }
}
