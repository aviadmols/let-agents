<?php

namespace App\Modules\Assistant\Tests;

use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use PHPUnit\Framework\TestCase;

/**
 * A least price a store sets for something, read by code and compared with each product's price (Rocks, 9.11: Layaway
 * "לפריטים שמחירם 2,000 ש״ח ומעלה" told yes for a ring of ₪650). For every store: a plan, free shipping, a gift, a way
 * to pay; in Hebrew and in English; never a sum with no word that makes it a least, never a percentage or a small fee.
 */
final class PriceMinimumsTest extends TestCase
{
    public function test_a_least_price_is_read_in_its_many_wordings_and_compared_with_each_product(): void
    {
        $products = ['p1' => ['price' => 650.0], 'p2' => ['price' => 2400.0], 'p3' => ['price' => null]];
        $read = fn (string $text): array => AnswerSiteQuestion::priceMinimums(['s1' => ['text' => $text]], $products);

        $layaway = $read('לפריטים שמחירם 2,000 ש״ח ומעלה, ובהם הטבעת הזו, ניתן לשריין בתוכנית Layaway במקדמה של 10%.');
        $this->assertSame([['amount' => '2,000 ש״ח ומעלה', 'ref' => 's1', 'below' => ['p1'], 'at_or_above' => ['p2']]], $layaway, 'the ring of ₪650 is under it, whatever "this ring" says');

        $this->assertSame(['p1'], $read('משלוח חינם בהזמנה מעל 1000 ש"ח')[0]['below'] ?? null, 'free shipping over a sum');
        $this->assertSame(['p2'], $read('מתנה בקנייה החל מ־2000 ₪')[0]['at_or_above'] ?? null, 'a gift from a sum');
        $this->assertSame(['p1'], $read('Free shipping on orders over $700.')[0]['below'] ?? null, 'English, another currency');
        $this->assertSame(['p1'], $read('Installments for items of ₪1,000 and up.')[0]['below'] ?? null, 'English, "and up"');

        $this->assertSame([], $read('המחיר של הטבעת 2,000 ש״ח.'), 'a price alone is no least');
        $this->assertSame([], $read('דמי ביטול של עד 5% או 100 ₪, הנמוך מביניהם.'), 'a fee, "up to", is no least');
        $this->assertSame([], $read('מקדמה של לפחות 10% מסך ההזמנה'), 'a percentage is no price');
        $this->assertSame([], AnswerSiteQuestion::priceMinimums(['s1' => ['text' => 'משלוח חינם מעל 300 ש״ח']], ['p1' => ['price' => null]]), 'no product with a price: nothing to compare');
    }

    public function test_the_stores_knowledge_never_points_at_a_product_it_was_written_beside(): void
    {
        $this->assertSame(
            'לפריטים שמחירם 2,000 ש״ח ומעלה, ניתן לשריין את הפריט בתוכנית Layaway.',
            AnswerSiteQuestion::withoutThisProduct('לפריטים שמחירם 2,000 ש״ח ומעלה, ובהם הטבעת הזו, ניתן לשריין את הפריט בתוכנית Layaway.'),
        );
        $this->assertSame('עבור מוצרים החל מ-2,000 ש"ח, ניתן להצטרף לתוכנית.', AnswerSiteQuestion::withoutThisProduct('עבור מוצרים החל מ-2,000 ש"ח (כמו הטבעת הזו), ניתן להצטרף לתוכנית.'));
        $this->assertSame('Free engraving on rings over $500.', AnswerSiteQuestion::withoutThisProduct('Free engraving on rings over $500, including this ring.'));
        foreach (['החנות הזו פתוחה עד 18:00.', 'הטבעת הזו היא דוגמה לסגנון ארט דקו.', 'משלוח חינם מעל 300 ש״ח.'] as $text) {
            $this->assertSame($text, AnswerSiteQuestion::withoutThisProduct($text), 'no pointer joined to a rule: as written');
        }
    }

    public function test_a_question_pointing_at_the_one_product_on_screen_is_about_it(): void
    {
        foreach (['האם אפשר לשריין אותה?', 'אפשר לשריין את הטבעת הזו?', 'יש את זה במידה 7?', 'is this one in stock?', 'can I reserve it?'] as $q) {
            $this->assertTrue(AnswerSiteQuestion::pointsAtOne($q), $q);
        }
        foreach (['אפשר לשריין מוצר בתשלומים?', 'מה מדיניות ההחזרות?', 'טבעת זהב לבן'] as $q) {
            $this->assertFalse(AnswerSiteQuestion::pointsAtOne($q), $q);
        }
    }
}
