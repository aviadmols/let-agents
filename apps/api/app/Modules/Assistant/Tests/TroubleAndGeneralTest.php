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

        // a purity or a material in percent is the trade's measure, not the store's word: "9K vs 14K" lost its figures (Rocks,
        // 16.11); a discount in percent still is the store's
        $gold = 'הם נבדלים בכמות הזהב: 9K הוא 37.5% זהב טהור, ו־14K הוא 58.5% זהב טהור.';
        $this->assertSame($gold, AnswerSiteQuestion::general($gold, ['9K הוא 37.5% זהב טהור, ו־14K הוא 58.5% זהב טהור.'])['answer']);
        $this->assertSame(['Merino is a fine wool; a 100% merino shirt has no other fibre.'], AnswerSiteQuestion::general('Merino is a fine wool; a 100% merino shirt has no other fibre.', ['Merino is a fine wool; a 100% merino shirt has no other fibre.'])['general']);
        $this->assertSame([], AnswerSiteQuestion::general('יש היום 20% הנחה על טבעות. כדאי למהר.', ['יש היום 20% הנחה על טבעות.'])['general']);
        $this->assertSame([], AnswerSiteQuestion::general('Rings are 15% off today. Hurry.', ['Rings are 15% off today.'])['general']);
    }

    public function test_a_revision_with_no_fix_keeps_what_it_did_not_name(): void
    {
        // Miss Nori, 16.11: "revise" named the second sentence and gave no fix; what was left was under the share kept
        // and the whole answer went, its card shown with no words
        $answer = 'האפשרות היקרה ביותר עד 500 ש״ח היא שרשרת זהב אריאל ב־₪109. אם תרצו משהו זול יותר, יש גם חגורה וטי שירט ב־₪79 כל אחת, שמתאימות לכל יום ולכל סגנון. תרצו שאכוון אתכם לפריט הכי אלגנטי?';
        $check = ['decision' => 'revise', 'revised' => '', 'safe' => true, 'injection' => false, 'picks' => [],
            'issues' => [['text' => 'יש גם חגורה וטי שירט ב־₪79 כל אחת', 'kind' => 'off_topic', 'severity' => 'major']]];
        $out = AnswerSiteQuestion::applyCheck($check, $answer, []);
        $this->assertSame('האפשרות היקרה ביותר עד 500 ש״ח היא שרשרת זהב אריאל ב־₪109. תרצו שאכוון אתכם לפריט הכי אלגנטי?', $out['answer']);

        // only the offer left is nothing to show
        $check['issues'][0]['text'] = 'האפשרות היקרה ביותר עד 500 ש״ח היא שרשרת זהב אריאל ב־₪109';
        $check['issues'][] = ['text' => 'יש גם חגורה וטי שירט ב־₪79 כל אחת', 'kind' => 'off_topic', 'severity' => 'major'];
        $this->assertSame('', AnswerSiteQuestion::applyCheck($check, $answer, [])['answer']);
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
