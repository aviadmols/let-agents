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
