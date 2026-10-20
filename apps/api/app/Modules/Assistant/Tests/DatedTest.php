<?php

namespace App\Modules\Assistant\Tests;

use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use App\Modules\Assistant\Support\Dated;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Sela Meir, 20.10: "תוך כמה זמן מגיע המשלוח?" was answered "המשלוח הבא יוצא ב־15.9", a banner on every
 * product page about the subscribers' shipment, its day long gone. A line of what is still to come on
 * a day already gone is never told; a date of what happened is.
 */
final class DatedTest extends TestCase
{
    public function test_a_date_still_to_come_that_is_gone_is_stale_and_a_date_of_what_happened_is_not(): void
    {
        $today = Carbon::create(2026, 10, 20);

        $this->assertTrue(Dated::past('המשלוח הבא יוצא ב-15.9', $today));
        $this->assertTrue(Dated::past('המבצע בתוקף עד 30/09/2026', $today));
        $this->assertTrue(Dated::past('Next shipment ships 15.9.26', $today));
        $this->assertFalse(Dated::past('המשלוח הבא יוצא ב-15.11', $today), 'still ahead');
        $this->assertFalse(Dated::past('המשלוח הבא יוצא ב-5.1', $today), 'January: next year\'s');
        $this->assertFalse(Dated::past('עומר שם טוב נחטף ב-7.10.23 מפסטיבל נובה', $today), 'what happened, no word of what is to come');
        $this->assertFalse(Dated::past('גבורה ותקומה אחרי 7.10', $today));
        $this->assertFalse(Dated::past('משלוחים חינם בקניה מעל 199₪', $today), 'no date');
        $this->assertFalse(Dated::past('יוצא במהדורה 2.5', $today), 'no day and month: a version');
    }

    public function test_an_answer_tells_no_stale_date_and_keeps_the_rest(): void
    {
        $answer = 'המשלוח עד הבית מגיע בתוך כ־10 ימי עסקים. בדף המוצר מופיע שהמשלוח הבא יוצא ב־'.Carbon::now()->subDays(35)->format('j.n').'.';

        [$left, $refusal] = AnswerSiteQuestion::gate($answer, 'המשלוח עד הבית מגיע בתוך כ־10 ימי עסקים', '');

        $this->assertNull($refusal);
        $this->assertSame('המשלוח עד הבית מגיע בתוך כ־10 ימי עסקים.', trim($left));
    }
}
