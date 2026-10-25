<?php

namespace App\Modules\Search\Tests;

use App\Modules\Search\Support\Colours;
use PHPUnit\Framework\TestCase;

/**
 * The colour a picture shows most, as its words name it first, and how near two such colours look: on Vans, a pale
 * yellow corduroy shirt's alike were a dark green one and a black one, and a beige shoe's a black one.
 */
final class ColourToneTest extends TestCase
{
    public function test_the_colour_named_first_is_the_one_shown_most_with_its_tone(): void
    {
        $this->assertSame(['family' => 'yellow', 'tone' => 'light'], Colours::dominant(['חולצה מכופתרת', 'קורדרוי', 'צהוב בהיר', 'לבן שבור']));
        $this->assertSame(['family' => 'green', 'tone' => 'dark'], Colours::dominant(['חולצה', 'ירוק כהה', 'שחור']));
        $this->assertSame(['family' => 'beige', 'tone' => 'light'], Colours::dominant(['נעלי ספורט', "בז'", 'שחור']));
        $this->assertNull(Colours::dominant(['תיק גב', 'כיס חזית']));
    }

    public function test_the_same_colour_is_nearest_then_another_of_the_same_tone_never_a_dark_one_for_a_light_one(): void
    {
        $paleYellow = ['family' => 'yellow', 'tone' => 'light'];

        $this->assertSame(1.0, Colours::near($paleYellow, ['family' => 'yellow', 'tone' => 'light']));
        $this->assertSame(0.8, Colours::near($paleYellow, ['family' => 'yellow', 'tone' => 'dark']));
        $this->assertSame(0.5, Colours::near($paleYellow, ['family' => 'white', 'tone' => 'light']), 'a white shirt beside a pale yellow one');
        $this->assertSame(0.0, Colours::near($paleYellow, ['family' => 'green', 'tone' => 'dark']), 'never a dark green one');
        $this->assertSame(0.0, Colours::near($paleYellow, null));
    }
}
