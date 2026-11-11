<?php

namespace App\Modules\Assistant\Tests;

use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use PHPUnit\Framework\TestCase;

/**
 * A reply in a conversation never closes by offering what the conversation already holds: a question the shopper
 * asked and was answered, or the offer an earlier answer made (Rocks, 11.11).
 */
final class OfferedAgainTest extends TestCase
{
    private const RESERVE = [
        'question' => 'איך מתבצע השריון?',
        'answer' => 'כדי לשריין בפועל, לוחצים על "תשלום חלקי" בדף המוצר ומשלמים מקדמה של לפחות 10%. אם תרצו, נוכל גם לעזור לכם להבין אם המוצר הספציפי מתאים לשריון כזה.',
    ];

    public function test_an_offer_of_what_was_asked_and_answered_is_left_out(): void
    {
        $answer = "אם תבטלו אחרי יותר מ־14 יום, הסכומים יינתנו כזיכוי לרכישה בחנות.\n\nהטבעת הזו מתאימה לשריון בתוכנית ה-Layaway.\n\nאם תרצו, נוכל לפרט גם איך מתבצע השריון בפועל.";

        $this->assertSame("אם תבטלו אחרי יותר מ־14 יום, הסכומים יינתנו כזיכוי לרכישה בחנות.\n\nהטבעת הזו מתאימה לשריון בתוכנית ה-Layaway.",
            AnswerSiteQuestion::withoutOfferedAgain($answer, [self::RESERVE]));
    }

    public function test_an_offer_of_a_new_step_stays(): void
    {
        $answer = 'הטבעת הזו מתאימה לשריון. תרצו שנעזור למצוא את המידה שלכם לפני ההזמנה?';

        $this->assertSame($answer, AnswerSiteQuestion::withoutOfferedAgain($answer, [self::RESERVE]));
    }

    public function test_without_a_conversation_or_an_offer_nothing_changes(): void
    {
        $offer = 'אפשר לשריין. אם תרצו, נוכל לפרט גם איך מתבצע השריון בפועל.';
        $this->assertSame($offer, AnswerSiteQuestion::withoutOfferedAgain($offer, []), 'a first question has nothing to repeat');

        $plain = 'כדי לשריין לוחצים על תשלום חלקי. השריון מתבצע מול הקופה.';
        $this->assertSame($plain, AnswerSiteQuestion::withoutOfferedAgain($plain, [self::RESERVE]), 'a sentence that offers nothing stays');
    }
}
