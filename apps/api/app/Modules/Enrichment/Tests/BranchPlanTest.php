<?php

namespace App\Modules\Enrichment\Tests;

use App\Modules\Enrichment\Scanning\BranchPlan;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 1.11: every category big enough is a branch of its own, however many there are; a category of 5 products or fewer
 * is not.
 */
final class BranchPlanTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    public function test_there_is_no_cap_on_branches_and_a_category_of_five_is_none(): void
    {
        $this->buildShop();

        $kinds = ['טבעות', 'עגילים', 'תליונים', 'צמידים', 'שרשראות', 'שעונים', 'סיכות', 'נזמים', 'קמעות', 'חפתים', 'כתרים', 'קופסאות', 'משקפיים', 'ארנקים'];
        foreach ($kinds as $c => $name) {
            $category = $this->category('c'.$c, $name);
            for ($p = 0; $p < 6; $p++) {
                $this->product("c{$c}p{$p}", "{$name} {$p}", 'תיאור', [$category]);
            }
        }
        $small = $this->category('small', 'אבני חן');
        for ($p = 0; $p < 5; $p++) {
            $this->product("s{$p}", "אבן {$p}", 'תיאור', [$small]);
        }

        $plan = $this->inShop(fn (): array => BranchPlan::of());
        $this->assertFalse($plan['whole']);
        $this->assertCount(14, $plan['branches'], 'all fourteen, past the old cap of twelve');
        $this->assertNotContains('אבני חן', array_column($plan['branches'], 'name'), 'five products are no branch');
        $this->assertSame(5, $plan['rest']);
    }
}
