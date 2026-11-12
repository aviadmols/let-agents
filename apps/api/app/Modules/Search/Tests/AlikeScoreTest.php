<?php

namespace App\Modules\Search\Tests;

use App\Modules\Search\Support\AlikeScore;
use Tests\TestCase;

/**
 * How alike two pieces of clothing or shoes look (Vans, 12.11): no signal decides alone, a print is read by how much it
 * covers too, and kids' and adults' are never alike.
 */
final class AlikeScoreTest extends TestCase
{
    public function test_a_print_on_a_part_is_nearer_another_on_a_part_than_stripes_all_over(): void
    {
        $floralOnChest = ['family' => 2, 'level' => 2];
        $graphicOnChest = ['family' => 8, 'level' => 2];
        $stripesAllOver = ['family' => 1, 'level' => 4];
        $plain = ['family' => null, 'level' => 0];

        $this->assertGreaterThan(AlikeScore::print($floralOnChest, $stripesAllOver), AlikeScore::print($floralOnChest, $graphicOnChest));
        $this->assertSame(0.0, AlikeScore::print($floralOnChest, $stripesAllOver));
        $this->assertSame(0.0, AlikeScore::print($plain, $stripesAllOver), 'plain beside all over: not alike');
        $this->assertSame(1.0, AlikeScore::print($stripesAllOver, ['family' => 1, 'level' => 3]), 'the same print');
        $this->assertSame(1.0, AlikeScore::print($plain, ['family' => null, 'level' => 0]));
    }

    public function test_a_near_picture_of_another_colour_can_beat_a_far_one_of_its_colour(): void
    {
        $far = AlikeScore::of(['look' => 0.1, 'colour' => 1.0, 'print' => 0.0, 'side' => 1.0, 'audience' => 1.0]);
        $near = AlikeScore::of(['look' => 0.9, 'colour' => 0.0, 'print' => 0.75, 'side' => 1.0, 'audience' => 1.0]);
        $this->assertGreaterThan($far, $near, 'the striped tee 26th by picture no longer leads by its colour');
    }

    public function test_colour_and_whom_it_is_for(): void
    {
        $this->assertSame(1.0, AlikeScore::colour(['שחור'], null, ['שחור'], null));
        $this->assertSame(0.0, AlikeScore::colour(['שחור'], null, ['לבן'], null));
        $this->assertSame(0.5, AlikeScore::colour(['שחור'], null, ['לבן', 'שחור', 'חום'], null), 'its colour among the other\'s');

        $this->assertSame(0.0, AlikeScore::audience(['kids'], ['men']));
        $this->assertSame(0.0, AlikeScore::audience(['women'], ['babies']));
        $this->assertSame(1.0, AlikeScore::audience(['men'], ['women', 'men']));
        $this->assertSame(1.0, AlikeScore::audience([], ['kids']), 'unknown');
    }
}
