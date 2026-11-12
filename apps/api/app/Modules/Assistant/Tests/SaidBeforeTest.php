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
}
