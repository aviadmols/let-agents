<?php

namespace App\Modules\Assistant\Tests;

use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use Tests\TestCase;

/**
 * What one visit already told the shopper is not told again (Rocks, 12.11: the newsletter's benefits, twice in a row).
 * Code, at serving, for any store.
 */
final class SaidBeforeTest extends TestCase
{
    private const BEFORE = [[
        'question' => 'איך נרשמים?',
        'answer' => 'נרשמים לניוזלטר, ואז קוד Welcome10 נשלח במייל אחרי ההרשמה. דרכו אפשר לקבל גם מבצעים, הנחות והטבה לכבוד יום הולדת. אם הקוד לא הגיע, כדאי לבדוק את תיקיית הספאם ואת כתובת המייל שנרשמה.',
    ]];

    public function test_a_sentence_already_told_is_left_out_and_what_is_new_stays(): void
    {
        $answer = 'יש גם הטבת יום הולדת של 10%: אחרי שמוסיפים תאריך לידה בהרשמה, קוד נשלח אוטומטית כשבועיים לפני יום ההולדת ותקף לחודש. אפשר גם להירשם כדי לקבל מבצעים, הנחות והטבה לכבוד יום ההולדת. אם תרצו, נוכל לעזור להבין איך נרשמים?';

        $this->assertSame(
            'יש גם הטבת יום הולדת של 10%: אחרי שמוסיפים תאריך לידה בהרשמה, קוד נשלח אוטומטית כשבועיים לפני יום ההולדת ותקף לחודש.',
            AnswerSiteQuestion::withoutSaidBefore($answer, self::BEFORE),
        );
    }

    public function test_an_answer_with_nothing_new_is_given_whole_and_without_a_visit_nothing_changes(): void
    {
        $again = 'דרכו אפשר לקבל גם מבצעים, הנחות והטבה לכבוד יום הולדת. אם הקוד לא הגיע, כדאי לבדוק את תיקיית הספאם ואת כתובת המייל שנרשמה.';
        $this->assertSame($again, AnswerSiteQuestion::withoutSaidBefore($again, self::BEFORE), 'asked again, answered again');

        $new = 'המשלוח חינם בהזמנה מעל 300 ש"ח, ומגיע תוך 3 ימי עסקים.';
        $this->assertSame($new, AnswerSiteQuestion::withoutSaidBefore($new, self::BEFORE));
        $this->assertSame($new, AnswerSiteQuestion::withoutSaidBefore($new, []));
    }

    /** Shilav, 19.11: a list of a branch's hours was joined into one line once a sentence before it was left out. */
    public function test_what_is_left_keeps_its_lines_and_its_list(): void
    {
        $before = [['question' => 'יש לכם חנות בגבעתיים?', 'answer' => 'הסניף שלנו בגבעתיים ממוקם בקניון גבעתיים בדרך יצחק רבין, בקומה השלישית של המתחם.']];
        $answer = "הסניף שלנו בגבעתיים ממוקם בקניון גבעתיים בדרך יצחק רבין, בקומה השלישית של המתחם.\n\nשעות הפעילות של הסניף:\n- ימים א׳–ה׳: 09:30–22:00\n- יום ו׳: 09:00–15:00\n\nמספר הטלפון של הסניף הוא 03-5569876.";

        $this->assertSame("שעות הפעילות של הסניף:\n- ימים א׳–ה׳: 09:30–22:00\n- יום ו׳: 09:00–15:00\n\nמספר הטלפון של הסניף הוא 03-5569876.",
            AnswerSiteQuestion::withoutSaidBefore($answer, $before, 'מה שעות הפתיחה?'));
    }

    /** ...and the same question asked again is answered again, whole: never a fragment of what was left ("הסניף נגיש ומספרו…"). */
    public function test_the_same_question_again_is_answered_whole(): void
    {
        $before = [['question' => 'יש לכם חנות בגבעתיים?', 'answer' => 'הסניף שלנו בגבעתיים ממוקם בקניון גבעתיים בדרך יצחק רבין 53, בקומה השלישית.']];
        $answer = 'הסניף שלנו בגבעתיים ממוקם בקניון גבעתיים בדרך יצחק רבין 53, בקומה השלישית. הסניף נגיש ומספרו הישיר הוא 03-5569876.';

        $this->assertSame($answer, AnswerSiteQuestion::withoutSaidBefore($answer, $before, 'יש לכם חנות בגבעתיים?'));
        $this->assertSame($answer, AnswerSiteQuestion::withoutSaidBefore($answer, $before, 'יש לכם חנות בגבעתיים'), 'its words, whatever its question mark');
    }
}
