<?php

namespace App\Modules\Assistant\Tests;

use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use Tests\TestCase;

/**
 * answer_site v24, by code (as Aviad asked 11.11, after reading another assistant's answers on Rocks): a shopper who tells
 * of trouble is read as such, and may be answered with one question and tap answers; a term of the store's trade may be
 * explained from general knowledge, and code keeps only the sentences of it that say nothing of the store.
 */
final class TroubleAndGeneralTest extends TestCase
{
    public function test_trouble_is_what_does_not_work_for_the_shopper_never_a_search(): void
    {
        foreach (['לא מצליחה לרכוש עגילים ולקבל הנחה 10%', 'לא קיבלתי קוד במייל', 'הקופון לא עובד', 'יש לי שגיאה בתשלום', 'the discount code doesn\'t work', 'I can\'t pay'] as $trouble) {
            $this->assertTrue(AnswerSiteQuestion::reportsTrouble($trouble), $trouble);
        }

        foreach (['לא מצליחה למצוא טבעת עם אבן אדומה', 'יש לכם טבעות זהב?', 'מה ההבדל בין GH ל-VS?', 'אפשר לשריין?'] as $question) {
            $this->assertFalse(AnswerSiteQuestion::reportsTrouble($question), $question);
        }
    }

    public function test_general_knowledge_is_kept_only_where_it_says_nothing_of_the_store(): void
    {
        $answer = 'GH היא דרגת הצבע של היהלום: כמעט חסר צבע. VS היא דרגת הניקיון: פגמים זעירים שקשה לראות גם בהגדלה של פי 10. רבות מהטבעות שלנו משובצות יהלומים בדרגות האלה.';

        $read = AnswerSiteQuestion::general($answer, ['GH היא דרגת הצבע של היהלום: כמעט חסר צבע.', 'VS היא דרגת הניקיון: פגמים זעירים שקשה לראות גם בהגדלה של פי 10.']);
        $this->assertSame($answer, $read['answer']);
        $this->assertCount(2, $read['general']);

        // a store fact passed off as general knowledge is taken out of the answer
        $laundered = 'יהלומים בדרגה הזו נמכרים בדרך כלל ב־3,000 ₪. VS היא דרגת הניקיון.';
        $read = AnswerSiteQuestion::general($laundered, ['יהלומים בדרגה הזו נמכרים בדרך כלל ב־3,000 ₪.', 'VS היא דרגת הניקיון.']);
        $this->assertSame('VS היא דרגת הניקיון.', $read['answer']);
        $this->assertSame(['VS היא דרגת הניקיון.'], $read['general']);

        $this->assertSame([], AnswerSiteQuestion::general($answer, ['משפט שלא נמצא בתשובה.'])['general'], 'only what the answer says');
        $this->assertSame([], AnswerSiteQuestion::general('אצלנו המשלוח חינם. כדאי לבדוק בתיקיית הספאם.', ['אצלנו המשלוח חינם.'])['general']);
    }

    public function test_the_writer_is_given_what_its_prompt_reads_not_what_its_number_says(): void
    {
        $this->assertFalse(AnswerSiteQuestion::promptReads('answer_site', 22, 'rules'));
        $this->assertTrue(AnswerSiteQuestion::promptReads('answer_site', 23, 'rules'));
        $this->assertTrue(AnswerSiteQuestion::promptReads('answer_site', 24, 'trouble'));
        // v26 is v22 with the trade's general knowledge: neither the store's rules nor trouble
        $this->assertFalse(AnswerSiteQuestion::promptReads('answer_site', 26, 'rules'));
        $this->assertFalse(AnswerSiteQuestion::promptReads('answer_site', 26, 'trouble'));
        $this->assertTrue(AnswerSiteQuestion::promptReads('answer_site', 26, 'general'));
        $this->assertFalse(AnswerSiteQuestion::promptReads('answer_site', 99, 'rules'), 'no such version');
    }

    public function test_tap_answers_stand_only_with_the_question_they_answer(): void
    {
        $asks = 'אצלנו אין כפל הנחות, כך שאם העגילים כבר במחיר מבצע, הקוד לא יחול עליהם. מה בדיוק קורה כשאתם מזינים את הקוד?';
        $given = ['העגילים כבר במחיר מבצע', 'הקוד לא מתקבל בקופה', 'לא קיבלתי קוד במייל', 'לא קיבלתי קוד במייל'];

        $this->assertSame(['העגילים כבר במחיר מבצע', 'הקוד לא מתקבל בקופה', 'לא קיבלתי קוד במייל'], AnswerSiteQuestion::choices($asks, $given));
        $this->assertSame([], AnswerSiteQuestion::choices('כדאי לבדוק בתיקיית הספאם.', $given), 'no question, no choices');
        $this->assertSame([], AnswerSiteQuestion::choices($asks, ['רק אחת']), 'two at least');
        $this->assertSame(['כן', 'לא'], AnswerSiteQuestion::choices($asks, ['כן', 'לא', 'הנחה של 20%', 'https://x.test', str_repeat('מילה ', 12)]), 'no price, no link, nothing long');
    }
}
