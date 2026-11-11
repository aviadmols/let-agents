<?php

namespace App\Modules\Assistant\Tests;

use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use PHPUnit\Framework\TestCase;

/**
 * An answer that says the exact thing is not here and offers products instead shows those products, also when the
 * checker kept the answer and took their cards away (Rocks, 11.11: "אין כאן עגיל מטפס בודד, אבל… העגיל פתית שלג…" with
 * two product pages under "מתוך:" and no card to open either from).
 */
final class OfferedInsteadTest extends TestCase
{
    private const ANSWER = 'אין כאן עגיל מטפס בודד, אבל יש לנו עגילים בודדים גדולים ומרשימים בזהב צהוב שיכולים להתאים. העגיל פתית שלג בזהב 18K הוא באורך 5 ס״מ, והעגיל הווינטג׳ התלוי בזהב 14K באורך 4.5 ס״מ.';

    private const PRODUCTS = [
        'p1' => ['external_id' => '16198', 'title' => 'עגיל בודד פתית שלג משובץ יהלומים - מק"ט 16198, זהב 18K'],
        'p2' => ['external_id' => '12721', 'title' => 'עגיל וינטאג בודד תלוי משובץ יהלומים, זהב 14K - מק"ט 12721'],
        'p3' => ['external_id' => '50973', 'title' => 'עגילים תלויים משובצים יהלומים - מק"ט 50973, זהב 18K'],
    ];

    private const PAGES = [
        's1' => ['source' => 'product', 'external_id' => '16198', 'title' => 'עגיל בודד פתית שלג'],
        's2' => ['source' => 'product', 'external_id' => '12721', 'title' => 'עגיל וינטאג בודד תלוי'],
        's3' => ['source' => 'content', 'title' => 'משלוחים'],
    ];

    private const OFFERED = [
        ['ref' => 'p1', 'external_id' => '16198', 'title' => 'עגיל בודד פתית שלג', 'why' => ''],
        ['ref' => 'p2', 'external_id' => '12721', 'title' => 'עגיל וינטאג בודד תלוי', 'why' => ''],
        ['ref' => 'p3', 'external_id' => '50973', 'title' => 'עגילים תלויים', 'why' => ''],
    ];

    public function test_the_products_a_kept_not_here_answer_offers_keep_their_cards(): void
    {
        $kept = AnswerSiteQuestion::offeredInstead(self::ANSWER, ['s1', 's2'], self::PAGES, self::OFFERED, [], self::PRODUCTS);

        $this->assertSame(['16198', '12721'], array_column($kept, 'external_id'), 'the two its pages are cited for; never one the answer does not offer');
    }

    public function test_the_checkers_choice_and_reasons_stay_and_the_order_is_the_answers(): void
    {
        $checked = [['ref' => 'p2', 'external_id' => '12721', 'title' => 'עגיל וינטאג בודד תלוי', 'why' => 'תלוי, 4.5 ס״מ']];

        $kept = AnswerSiteQuestion::offeredInstead(self::ANSWER, ['s1', 's2'], self::PAGES, self::OFFERED, $checked, self::PRODUCTS);

        $this->assertSame(['16198', '12721'], array_column($kept, 'external_id'));
        $this->assertSame('תלוי, 4.5 ס״מ', $kept[1]['why']);
    }

    public function test_an_answer_that_says_nothing_is_missing_stays_as_the_checker_left_it(): void
    {
        // presented as what was asked, a product the checker found unfit is no alternative
        $answer = 'העגיל פתית שלג בזהב 18K הוא עגיל מטפס גדול ומרשים.';
        $this->assertSame([], AnswerSiteQuestion::offeredInstead($answer, ['s1'], self::PAGES, self::OFFERED, [], self::PRODUCTS));
        // and a removed answer brings nothing back
        $this->assertSame([], AnswerSiteQuestion::offeredInstead('', ['s1'], self::PAGES, self::OFFERED, [], self::PRODUCTS));
    }
}
