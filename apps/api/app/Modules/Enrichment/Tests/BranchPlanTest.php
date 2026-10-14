<?php

namespace App\Modules\Enrichment\Tests;

use App\Modules\Enrichment\Scanning\BranchPlan;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A store's catalog divided into branches, one vocabulary each: a big category is split into its
 * big subcategories so that diamonds are not buried inside a general vocabulary, and a category too
 * small for its own is left to the rest.
 */
final class BranchPlanTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    public function test_a_big_category_is_split_into_its_big_subcategories_and_small_ones_are_left_to_the_rest(): void
    {
        $this->buildShop();
        $jewelry = $this->category('j', 'תכשיטים');
        $rings = $this->category('j-rings', 'טבעות', 'j', ['תכשיטים', 'טבעות']);
        $earrings = $this->category('j-ear', 'עגילים', 'j', ['תכשיטים', 'עגילים']);
        $misc = $this->category('j-misc', 'שונות', 'j', ['תכשיטים', 'שונות']);
        $tools = $this->category('t', 'כלי עבודה');
        $tiny = $this->category('x', 'מבצעים');

        $n = 0;
        foreach ([[$rings, 20], [$earrings, 20], [$misc, 3], [$tools, 18], [$tiny, 4]] as [$category, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $this->product('p'.(++$n), 'מוצר '.$n, '', [$category]);
            }
        }

        $plan = $this->inShop(fn (): array => BranchPlan::of(minProducts: 15, splitPercent: 35, maxBranches: 12));

        $this->assertSame(65, $plan['total']);
        $this->assertSame(['j-ear', 'j-rings', 't'], array_column($plan['branches'], 'root'), 'jewelry holds 66% of the catalog and two big children: split; the biggest first, ties by id');
        $this->assertSame([20, 20, 18], array_column($plan['branches'], 'products'));
        $this->assertSame(['j', 'j', null], array_column($plan['branches'], 'split_from'));
        $this->assertSame(7, $plan['rest'], 'the 3 miscellaneous pieces and the 4 in the small category');
        $this->assertSame('עגילים', $plan['branches'][0]['name']);
        $this->assertSame('תכשיטים › עגילים', $plan['branches'][0]['path']);
        $this->assertMatchesRegularExpression('/^branch_[a-z0-9_]{1,30}$/', $plan['branches'][0]['key']);
        $this->assertSame('branch_j_ear', $plan['branches'][0]['key']);

        $this->assertSame($plan, $this->inShop(fn (): array => BranchPlan::of(minProducts: 15, splitPercent: 35, maxBranches: 12)), 'the same catalog, the same plan');
    }

    public function test_a_category_that_is_not_a_big_share_stays_whole_and_the_most_branches_cut_the_smallest(): void
    {
        $this->buildShop();
        $a = $this->category('a', 'ריהוט');
        $a1 = $this->category('a1', 'ספות', 'a', ['ריהוט', 'ספות']);
        $a2 = $this->category('a2', 'שולחנות', 'a', ['ריהוט', 'שולחנות']);
        $b = $this->category('b', 'תאורה');
        $c = $this->category('c', 'שטיחים');

        $n = 0;
        foreach ([[$a1, 16], [$a2, 16], [$b, 40], [$c, 20]] as [$category, $count]) {
            for ($i = 0; $i < $count; $i++) {
                $this->product('p'.(++$n), 'מוצר '.$n, '', [$category]);
            }
        }

        // Furniture is 32 of 92: under 40%, so it stays one branch.
        $whole = $this->inShop(fn (): array => BranchPlan::of(minProducts: 15, splitPercent: 40, maxBranches: 12));
        $this->assertSame(['b', 'a', 'c'], array_column($whole['branches'], 'root'));
        $this->assertSame([40, 32, 20], array_column($whole['branches'], 'products'));
        $this->assertSame(0, $whole['rest']);

        $cut = $this->inShop(fn (): array => BranchPlan::of(minProducts: 15, splitPercent: 40, maxBranches: 2));
        $this->assertSame(['b', 'a'], array_column($cut['branches'], 'root'));
        $this->assertSame(20, $cut['rest'], 'what no branch covers is counted, not lost');
    }

    public function test_the_shops_own_settings_are_the_defaults(): void
    {
        $this->buildShop();
        $cat = $this->category('only', 'הכול');
        for ($i = 1; $i <= 16; $i++) {
            $this->product('p'.$i, 'מוצר '.$i, '', [$cat]);
        }

        $plan = $this->inShop(fn (): array => BranchPlan::of());

        $this->assertSame(['only'], array_column($plan['branches'], 'root'), 'at least 15 products by default');
    }
}
