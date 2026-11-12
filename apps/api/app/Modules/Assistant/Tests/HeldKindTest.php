<?php

namespace App\Modules\Assistant\Tests;

use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use Tests\TestCase;

/**
 * answer_site v25, by code (Rocks, 12.11: "יש משהו עם זהב צהב?" after solitaire rings was given pendants, and "אין לנו
 * מידע כתוב" was marked answered). Read from each store's own titles and categories, never a list of kinds: the same
 * for a jeweller, a bookshop or a tool store.
 */
final class HeldKindTest extends TestCase
{
    private const RINGS = [['question' => 'יש לכם טבעות סוליטר?', 'answer' => 'כן, יש לנו טבעות סוליטר בזהב לבן ובזהב צהוב.']];

    /** @return list<array<string, mixed>> */
    private function jewellery(): array
    {
        return [
            ['external_id' => '1', 'title' => 'תליון מפתח עדין - זהב 9K', 'category' => 'תכשיטים > תליונים'],
            ['external_id' => '2', 'title' => 'טבעת סוליטר משובצת יהלום - זהב 14K', 'category' => 'תכשיטים > טבעות'],
            ['external_id' => '3', 'title' => 'עגילי חישוק זהב צהוב', 'category' => 'תכשיטים > עגילים'],
            ['external_id' => '4', 'title' => 'טבעת וינטג\' בריטית', 'category' => 'תכשיטים > טבעות > וינטג\''],
        ];
    }

    private function ids(array $products): array
    {
        return array_column($products, 'external_id');
    }

    public function test_a_reply_naming_no_kind_keeps_the_kind_asked_before(): void
    {
        $this->assertSame(['2', '4'], $this->ids(AnswerSiteQuestion::heldKind('יש משהו עם זהב צהב?', self::RINGS, $this->jewellery())));
        $this->assertSame(['2', '4'], $this->ids(AnswerSiteQuestion::heldKind('כן', self::RINGS, $this->jewellery())));
    }

    public function test_a_reply_naming_a_kind_is_answered_from_all(): void
    {
        $this->assertSame(['1', '2', '3', '4'], $this->ids(AnswerSiteQuestion::heldKind('ועגילים?', self::RINGS, $this->jewellery())));
        $this->assertSame(['1', '2', '3', '4'], $this->ids(AnswerSiteQuestion::heldKind('יש גם תליון כזה?', self::RINGS, $this->jewellery())));
    }

    public function test_nothing_is_held_without_a_conversation_or_a_kind_in_it(): void
    {
        $this->assertSame(['1', '2', '3', '4'], $this->ids(AnswerSiteQuestion::heldKind('יש משהו עם זהב צהב?', [], $this->jewellery())));
        $this->assertSame(['1', '2', '3', '4'], $this->ids(AnswerSiteQuestion::heldKind('יש משהו עם זהב צהב?', [['question' => 'מה שעות הפתיחה?', 'answer' => 'א-ה 10-19.']], $this->jewellery())));
        // none of the kind asked before among them: all of them, as before
        $pendants = [$this->jewellery()[0], ['external_id' => '5', 'title' => 'תליון לב', 'category' => 'תליונים']];
        $this->assertSame(['1', '5'], $this->ids(AnswerSiteQuestion::heldKind('יש משהו עם זהב צהב?', self::RINGS, $pendants)));
    }

    public function test_any_store_by_its_own_words(): void
    {
        $tools = [
            ['external_id' => 'a', 'title' => 'מקדחה רוטטת 18V', 'category' => 'כלי עבודה > מקדחות'],
            ['external_id' => 'b', 'title' => 'מסור עגול 1400W', 'category' => 'כלי עבודה > מסורים'],
            ['external_id' => 'c', 'title' => 'מקדחה אלחוטית 12V', 'category' => 'כלי עבודה > מקדחות'],
        ];
        $this->assertSame(['a', 'c'], $this->ids(AnswerSiteQuestion::heldKind('יש משהו של מקיטה?', [['question' => 'אני מחפש מקדחות', 'answer' => 'יש לנו כמה מקדחות.']], $tools)));

        $shirts = [
            ['external_id' => 'x', 'title' => 'Linen shirt', 'category' => 'Men > Shirts'],
            ['external_id' => 'y', 'title' => 'Wool scarf', 'category' => 'Men > Scarves'],
        ];
        $this->assertSame(['x'], $this->ids(AnswerSiteQuestion::heldKind('anything in blue?', [['question' => 'do you have shirts?', 'answer' => 'Yes.']], $shirts)));
    }

    public function test_an_offer_from_a_least_price_says_the_amount_in_the_store_s_own_sentence(): void
    {
        $byRef = [
            's1' => ['title' => 'Layaway', 'text' => 'תשלום חלקי ללא תפיסת מסגרת. תוכנית Layaway זמינה לפריטים שמחירם 2,000 ש״ח ומעלה. מקדמה של 10%.'],
            's2' => ['title' => 'משלוחים', 'text' => 'משלוח חינם בהזמנה מעל 300 ש״ח.'],
        ];
        $products = ['p1' => ['price' => 2600.0], 'p2' => ['price' => 650.0]];
        $minimums = AnswerSiteQuestion::priceMinimums($byRef, $products);
        $answer = 'כן, אפשר לשריין את הטבעת הזו בתוכנית Layaway דרך לחיצה על ״תשלום חלקי״. נדרשת מקדמה של לפחות 10%.';

        $this->assertSame(
            'כן, אפשר לשריין את הטבעת הזו בתוכנית Layaway דרך לחיצה על ״תשלום חלקי״. תוכנית Layaway זמינה לפריטים שמחירם 2,000 ש״ח ומעלה. נדרשת מקדמה של לפחות 10%.',
            AnswerSiteQuestion::withLeastPrice($answer, $minimums, 'p1', ['s1'], $byRef),
        );

        // already said, in any writing of it
        $said = 'התוכנית מיועדת לפריטים מ־2000 ש״ח ומעלה, והטבעת הזו נכללת בה.';
        $this->assertSame($said, AnswerSiteQuestion::withLeastPrice($said, $minimums, 'p1', ['s1'], $byRef));
        // a product under the amount, no product asked about, the passage not cited: as it was
        $this->assertSame($answer, AnswerSiteQuestion::withLeastPrice($answer, $minimums, 'p2', ['s1'], $byRef));
        $this->assertSame($answer, AnswerSiteQuestion::withLeastPrice($answer, $minimums, null, ['s1'], $byRef));
        $this->assertSame($answer, AnswerSiteQuestion::withLeastPrice($answer, $minimums, 'p1', ['s2'], $byRef));
    }

    public function test_nothing_written_is_no_answer(): void
    {
        foreach ([
            'אנחנו עוסקים בתכשיטי זהב ויהלומים, ובחומר שבידינו אין לנו מענה כתוב על החלפת סוללות לשעונים. צוות החנות ישמח לעזור לכם.',
            'אין לנו כאן מידע כתוב על החלפת סוללות לשעונים.',
            'אין מידע לגבי משלוח לחו"ל.',
            'We have nothing written about gift wrapping.',
        ] as $answer) {
            $this->assertTrue(AnswerSiteQuestion::saysNothingWritten($answer), $answer);
        }

        foreach ([
            'אין כרגע במלאי, אבל אפשר להזמין.',
            'אין לנו מידע כתוב על משלוח לחו"ל, אבל בארץ המשלוח חינם מעל 300 ש"ח.',
            'אין כפל הנחות אצלנו.',
            'המשלוח חינם מעל 300 ש"ח.',
            'Shipping is free over $50.',
        ] as $answer) {
            $this->assertFalse(AnswerSiteQuestion::saysNothingWritten($answer), $answer);
        }
    }
}
