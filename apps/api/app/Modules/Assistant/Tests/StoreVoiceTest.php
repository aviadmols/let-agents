<?php

namespace App\Modules\Assistant\Tests;

use App\Modules\Assistant\Support\StoreVoice;
use PHPUnit\Framework\TestCase;

/**
 * The store speaks as "we" (Rocks, 9.11: "אם תרצו, אני יכול לפרט גם מה קורה במקרה של ביטול."): the first person
 * singular, a man's or a woman's, is put back into the store's plural; words that only look like it stay as written.
 */
final class StoreVoiceTest extends TestCase
{
    public function test_the_first_person_singular_becomes_the_stores_plural(): void
    {
        $this->assertSame('אם תרצו, נוכל לפרט גם מה קורה במקרה של ביטול.', StoreVoice::plural('אם תרצו, אני יכול לפרט גם מה קורה במקרה של ביטול.'));
        $this->assertSame('נוכל לעזור למצוא טבעת.', StoreVoice::plural('אני יכולה לעזור למצוא טבעת.'));
        $this->assertSame('נשמח לבדוק, ונעזור לכם לבחור.', StoreVoice::plural('אשמח לבדוק, ואעזור לכם לבחור.'));
        $this->assertSame('אם תרצו, נוכל למיין לפי מחיר.', StoreVoice::plural('אם תרצו, אוכל למיין לפי מחיר.'));
        $this->assertSame('אנחנו ממליצים על הטבעת הזו, ונפרט עליה עוד.', StoreVoice::plural('אני ממליצה על הטבעת הזו, ואפרט עליה עוד.'));
    }

    public function test_the_shopper_is_addressed_in_a_form_that_fixes_no_gender(): void
    {
        foreach ([
            // Rocks, 20.11: the question the answer ended with
            'איזה אורך היית מעדיפה?' => 'איזה אורך עדיף לך?',
            'האם היית מעדיף זהב לבן או צהוב?' => 'האם עדיף לך זהב לבן או צהוב?',
            // live answers, 30 days to 20.11
            'אם תרצה, אפשר לבחור דגם אחר בלי שרשרת.' => 'אם מתאים לך, אפשר לבחור דגם אחר בלי שרשרת.',
            'לפי שיטת המידות שבה את משתמשת. אם תרצי, נוכל לעזור לך.' => 'לפי שיטת המידות שבה משתמשים. אם מתאים לך, נוכל לעזור לך.',
            'אצלנו בגלריה תוכלי למצוא מגוון תכשיטים.' => 'אצלנו בגלריה אפשר למצוא מגוון תכשיטים.',
            'אם את מחפשת מתנה, יש לנו כמה רעיונות.' => 'אם חיפשת מתנה, יש לנו כמה רעיונות.',
            'מה שאתה מחפש נמצא כאן.' => 'מה שחיפשת נמצא כאן.',
            'אם אתה מעוניין בטבעת, נשמח לעזור.' => 'אם יש לך עניין בטבעת, נשמח לעזור.',
            'אם אתה צריך עזרה, אנחנו כאן.' => 'אם צריך עזרה, אנחנו כאן.',
            'מה תרצה לדעת עליה?' => 'מה רצית לדעת עליה?',
            'יש שלושה דגמים. תוכל לבחור לפי צבע.' => 'יש שלושה דגמים. אפשר לבחור לפי צבע.',
            'תרצי שנראה עוד?' => 'רצית שנראה עוד?',
            'מחפשת משהו מסוים? נשמח לעזור.' => 'חיפשת משהו מסוים? נשמח לעזור.',
            // before what is wanted: what was wanted
            'אם תרצי משהו מוחשי יותר, הטבעת מתאימה.' => 'אם רצית משהו מוחשי יותר, הטבעת מתאימה.',
            'ואם תרצה לבדוק איתנו אפשרויות נוספות, נשמח.' => 'ואם רצית לבדוק איתנו אפשרויות נוספות, נשמח.',
            // and the store, never one woman or man, with "ש" before it too
            'הייתי מתחילה מטבעות הווינטג׳.' => 'היינו מתחילים מטבעות הווינטג׳.',
            'רצית שאכוון לעוד אפשרות?' => 'רצית שנכוון לעוד אפשרות?',
        ] as $slip => $neutral) {
            $this->assertSame($neutral, StoreVoice::plural($slip), $slip);
        }
    }

    public function test_the_same_verbs_about_a_product_or_a_person_named_stay_as_written(): void
    {
        foreach ([
            'הטבעת צריכה לעבור על המפרק בנוחות.',
            'הכלה תרצה משהו עדין.',
            'המערכת תוכל לשרת גם חנויות גדולות.',
            'זו טבעת מוכנה מהקולקציה שלנו.',
            'אם תרצו, אפשר לבחור לפי צבע.',          // the plural is already no one gender
            'אם היית רוצה לראות עוד, נשמח.',          // "רוצה" is a man's and a woman's alike
            'יש לנו את מקדח S.D.S במבחר גדלים.',     // "את" before a thing, not "you"
            'ספר על אדם שמחפש זהות.',
        ] as $text) {
            $this->assertSame($text, StoreVoice::plural($text), $text);
        }
    }

    public function test_what_only_looks_like_it_stays_as_written(): void
    {
        foreach ([
            'יש לנו אוכל לכלבים.',            // food, not "I can"
            'אנחנו נשמח לעזור.',              // already the store's
            'הטבעת נשלחת תוך 3 ימי עסקים.',   // no first person at all
            'אני והצוות נשמח לעזור.',          // "אני" not before a verb on the list
            'I can help you choose.',          // not Hebrew
        ] as $text) {
            $this->assertSame($text, StoreVoice::plural($text), $text);
        }
    }
}
