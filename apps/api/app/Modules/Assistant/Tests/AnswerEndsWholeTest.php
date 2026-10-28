<?php

namespace App\Modules\Assistant\Tests;

use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use ReflectionMethod;
use Tests\TestCase;

/**
 * An answer never ends in the middle of a word (Gil Travel, 28.10: "…and bring in local travel professio"), and the
 * team sees every product an answer showed, not only its picks.
 */
final class AnswerEndsWholeTest extends TestCase
{
    public function test_a_long_answer_cut_in_a_sentence_ends_at_its_last_whole_one(): void
    {
        $first = 'Gil Travel is a Philadelphia-based travel company with more than 50 years of experience planning Jewish heritage trips, and it builds custom itineraries for families, congregations, organizations and private travelers with kosher dining and Shabbat-friendly logistics at every stop.';
        $this->assertSame($first, AnswerSiteQuestion::endsWhole($first."\n\nWe also handle full-service planning, including visas, flights and other details, and bring in local travel professio"));
        $this->assertSame($first, AnswerSiteQuestion::endsWhole($first), 'whole: as it was');
        $this->assertSame('Try the classic band', AnswerSiteQuestion::endsWhole('Try the classic band'), 'a short answer with no full stop is as it was');
        $this->assertSame('Here are three options:', AnswerSiteQuestion::endsWhole('Here are three options:'));
    }

    public function test_every_product_the_answer_showed_is_kept_with_the_question(): void
    {
        $shown = new ReflectionMethod(AnswerSiteQuestion::class, 'shownBy');

        $this->assertSame(['7', '8', '9'], $shown->invoke(null, [
            'picks' => [['external_id' => '5']],
            'named' => ['ids' => ['p:7', '5']],
            'set' => ['ids' => ['p:8', 'p:9', 'p:7']],
        ]), 'named, then the set, each once, never a pick again');
    }
}
