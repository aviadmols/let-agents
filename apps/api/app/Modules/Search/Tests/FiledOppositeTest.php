<?php

namespace App\Modules\Search\Tests;

use App\Modules\Search\Actions\BuildSearchIndex;
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

    public function test_a_tag_that_agrees_or_says_nothing_of_it_stays(): void
    {
        $this->assertFalse(BuildSearchIndex::contradicts('חולצה ארוכה Core Thermal', 'חולצות ארוכות'));
        $this->assertFalse(BuildSearchIndex::contradicts('חולצה ארוכה Core Thermal', 'חולצות גברים'));
        $this->assertFalse(BuildSearchIndex::contradicts('חולצה Core Thermal', 'חולצות טי קצרות'), 'the title says nothing of it');
        $this->assertFalse(BuildSearchIndex::contradicts('שמלה קצרה עם שרוול ארוך', 'שמלות קצרות'), 'the title says both');
        $this->assertFalse(BuildSearchIndex::contradicts('טבעת זהב', 'SALE'));
    }
}
