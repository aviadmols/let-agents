<?php

namespace App\Modules\Assistant\Tests;

use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use Tests\TestCase;

/**
 * How an answer closes, by code (Rocks, 11.11): a reply never offers what the conversation already holds; an answer the
 * pages do not settle closes with the store's team, never an offer to check what only a person can; and a reply that
 * says the answer before it again hands over to the team.
 */
final class OfferedAgainTest extends TestCase
{
    private const RESERVE = [
        'question' => 'איך מתבצע השריון?',
        'answer' => 'כדי לשריין בפועל, לוחצים על "תשלום חלקי" בדף המוצר ומשלמים מקדמה של לפחות 10%. אם תרצו, נוכל גם לעזור לכם להבין אם המוצר הספציפי מתאים לשריון כזה.',
    ];

    private const SHORTEN = "קיצור קבוע אפשרי בחלק מהדגמים ותלוי בסוג החוליות, במבנה הסוגר ובמיקום התליון או האלמנטים הקבועים.\n\nבשרשרת עם חוליות גדולות האורך הסופי עשוי להשתנות בקפיצות לפי גודל כל חוליה.\n\nכדי לדעת אם קיצור מתאים לשרשרת הזו, צריך לבדוק אותה.";

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

    public function test_an_answer_the_pages_do_not_settle_closes_with_the_team_not_an_offer_to_check(): void
    {
        $answer = self::SHORTEN."\n\nתרצו שנעזור לבדוק את האורך המתאים לכם?";

        $this->assertSame(self::SHORTEN."\n\nצוות החנות ישמח לעזור לכם.", AnswerSiteQuestion::teamWhenUnsettled($answer, 'he'));

        $settled = 'כן, אפשר להחזיר תוך 14 יום באריזה המקורית. תרצו שנעזור למצוא מוצר אחר?';
        $this->assertSame($settled, AnswerSiteQuestion::teamWhenUnsettled($settled, 'he'), 'an answer that answers keeps its offer');
    }

    public function test_a_reply_that_says_the_answer_before_again_hands_over_to_the_team(): void
    {
        $again = "השרשרת הזו אפשר לקצר קבוע רק אם מבנה החוליות והסוגר מאפשרים את זה, ובשרשרת עם חוליות גדולות האורך הסופי יכול להשתנות בקפיצות לפי גודל כל חוליה.\n\nכדי לדעת אם זה מתאים בדיוק לשרשרת הזו, צריך לבדוק אותה.";
        $conversation = [['question' => 'האם אפשר לקצר את השרשרת הזאת?', 'answer' => self::SHORTEN]];

        $this->assertSame('את זה צריך לבדוק מול הצוות שלנו. צוות החנות ישמח לעזור לכם.', AnswerSiteQuestion::notSaidAgain($again, $conversation, 'he'));

        $new = 'אפשר לבחור אורך אחר מהרשימה: 45, 50 או 55 ס"מ, וההזמנה מגיעה תוך שלושה ימי עסקים לכל הארץ.';
        $this->assertSame($new, AnswerSiteQuestion::notSaidAgain($new, $conversation, 'he'), 'a reply that says something new stays');
    }

    public function test_a_yes_to_our_own_offer_goes_forward_with_a_question_never_to_the_team(): void
    {
        // Rocks 11.11: "כן" to the offer, the layaway said again, and the shopper told the team would check
        $layaway = "כן, אפשר לשריין פריט דרך תוכנית Layaway לפריטים שמחירם 2,000 ש\"ח ומעלה, בלחיצה על \"תשלום חלקי\" בדף המוצר.\n\nמשאירים מקדמה של לפחות 10% מסכום ההזמנה, ואת היתרה אפשר לשלם בתשלומים חודשיים עד 10 חודשים, ללא ריבית או דמי טיפול.\n\nלפריטים שמחירם נמוך מ־2,000 ש\"ח אפשר לרכוש ישירות באתר.\n\nתרצו שנעזור להבין אם הפריט שמעניין אתכם מתאים לשריון?";
        $conversation = [['question' => 'האם יש לי דרך לשריין אצלכם מוצר עד שאני אבוא לראות אותו?', 'answer' => $layaway]];
        $again = 'אפשר לשריין פריט דרך תוכנית Layaway לפריטים שמחירם 2,000 ש"ח ומעלה, בלחיצה על "תשלום חלקי" בדף המוצר. משאירים מקדמה של לפחות 10% מסכום ההזמנה, ואת היתרה בתשלומים חודשיים עד 10 חודשים.';
        $which = 'בשמחה. על איזה מוצר מדובר? כתבו את שם המוצר או את המק"ט.';

        $this->assertSame($which, AnswerSiteQuestion::forwardOnYes('כן', $again, $conversation, 'he'), 'the same answer again');
        $this->assertSame($which, AnswerSiteQuestion::forwardOnYes('כן בבקשה!', 'כדי לדעת אם הפריט מתאים צריך לבדוק אותו.', $conversation, 'he'), '"it needs checking"');
        $this->assertNull(AnswerSiteQuestion::forwardOnYes('כן', 'הנה שלוש טבעות מעל 2,000 ש"ח שאפשר לשריין: טבעת אוניקס, טבעת ספיר וטבעת יהלום.', $conversation, 'he'), 'a yes the writer carried forward stays');
        $this->assertNull(AnswerSiteQuestion::forwardOnYes('יש לכם טבעות זהב?', $again, $conversation, 'he'), 'only a yes');

        // an offer about no one product: what they need
        $returns = [['question' => 'מה מדיניות ההחזרות?', 'answer' => 'אפשר להחזיר תוך 14 יום באריזה המקורית. תרצו שנסביר איך שולחים את ההחזרה?']];
        $this->assertSame('בשמחה. ספרו לנו קצת יותר במה תרצו עזרה.', AnswerSiteQuestion::forwardOnYes('בטח', 'אפשר להחזיר תוך 14 יום באריזה המקורית, ואת ההחזרה שולחים אלינו.', $returns, 'he'));

        // an answer before that closed with no offer: nothing changes
        $this->assertNull(AnswerSiteQuestion::forwardOnYes('כן', $again, [['question' => 'שריון?', 'answer' => 'אפשר לשריין פריטים מעל 2,000 ש"ח.']], 'he'));
    }

    public function test_without_a_conversation_or_an_offer_nothing_changes(): void
    {
        $offer = 'אפשר לשריין. אם תרצו, נוכל לפרט גם איך מתבצע השריון בפועל.';
        $this->assertSame($offer, AnswerSiteQuestion::withoutOfferedAgain($offer, []), 'a first question has nothing to repeat');

        $plain = 'כדי לשריין לוחצים על תשלום חלקי. השריון מתבצע מול הקופה.';
        $this->assertSame($plain, AnswerSiteQuestion::withoutOfferedAgain($plain, [self::RESERVE]), 'a sentence that offers nothing stays');
    }
}
