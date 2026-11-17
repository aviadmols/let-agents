<?php

namespace App\Modules\Assistant\Tests;

use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use PHPUnit\Framework\TestCase;

/**
 * "איזו מתנה מתאימה לאשתי עד 1500 ש"ח, משהו עדין עם יהלום": the writer picked three rings in the budget and wrote of them,
 * the gate took the sentence that said the budget back as a price no page writes, nothing was left, and the checker
 * was sent "" and refused the whole (Rocks, 17.11).
 */
final class ShopperBudgetGateTest extends TestCase
{
    public function test_the_budget_the_shopper_named_is_no_price_of_the_store(): void
    {
        $question = 'איזו מתנה מתאימה לרכוש לאשתי עד 1500 ש"ח, משהו עדין עם יהלום';
        $answer = 'למתנה עדינה עם יהלום עד 1,500 ש״ח יש לנו כמה אפשרויות יפות.';
        $known = "טבעת יהלום\n".$question;

        [, $refusal] = AnswerSiteQuestion::gate($answer, $known, 'טבעת יהלום 1100');
        $this->assertSame('price', $refusal, 'without the question, the sum is one no page writes');

        [$left, $refusal, $out] = AnswerSiteQuestion::gate($answer, $known, "טבעת יהלום 1100\n".AnswerSiteQuestion::sumsOf($question));
        $this->assertSame([$answer, null, 0], [$left, $refusal, $out]);
    }

    public function test_a_sum_the_shopper_did_not_name_is_still_taken_out(): void
    {
        $question = 'מתנה עד 1500 ש"ח';
        [$left] = AnswerSiteQuestion::gate('יש לנו טבעת יפה במחיר 990 ש״ח.', $question, AnswerSiteQuestion::sumsOf($question));

        $this->assertSame('', $left);
    }

    public function test_a_phone_number_in_the_question_is_no_sum(): void
    {
        $this->assertSame('1500 054', AnswerSiteQuestion::sumsOf('עד 1500 ש"ח, הטלפון שלי 054-1234567'));
        $this->assertSame('1,500', AnswerSiteQuestion::sumsOf('עד 1,500 שקל'));
    }

    public function test_what_is_clean_stays_however_little_when_asked_to(): void
    {
        $answer = 'הטבעת הזו עדינה ומתאימה מאוד למתנה. המחיר שלה 990 ש״ח אחרי הנחה מיוחדת של החנות לכבוד החג, וזו הזדמנות שלא חוזרת לעוד הרבה מאוד זמן.';

        [$left] = AnswerSiteQuestion::gate($answer, 'טבעת', 'טבעת');
        $this->assertSame('', $left, 'too little of the answer is left');

        [$left, $refusal] = AnswerSiteQuestion::gate($answer, 'טבעת', 'טבעת', 0.0);
        $this->assertSame(['הטבעת הזו עדינה ומתאימה מאוד למתנה.', null], [$left, $refusal]);
    }
}
