<?php

namespace App\Modules\Assistant\Tests;

use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use PHPUnit\Framework\TestCase;

/**
 * A reply that asks only a price goes on with the conversation's subject (Rocks, rubies, 18.11): "מה יש לכם עד 1500 ש״ח?"
 * after ruby rings is ruby rings up to 1500, in any store and either language; a reply that names anything else stays.
 */
final class ReplyKeepsSubjectTest extends TestCase
{
    /** @return array{min: float|null, max: float|null, words: string} */
    private static function read(string $text): array
    {
        if (preg_match('/(?:עד|under|up to)\s*(\d+)\s*(?:ש["״]?ח|₪)?/u', $text, $m) === 1) {
            return ['min' => null, 'max' => (float) $m[1], 'words' => trim(str_replace($m[0], ' ', $text))];
        }

        return ['min' => null, 'max' => null, 'words' => $text];
    }

    private static function carried(string $reply, array $questions): ?string
    {
        return AnswerSiteQuestion::carriedSubject($reply, array_map(fn (string $q): array => ['question' => $q, 'answer' => 'תשובה'], $questions), fn (string $t): array => self::read($t));
    }

    public function test_a_price_alone_goes_on_with_the_last_question_that_has_words_of_its_own(): void
    {
        $this->assertSame('יש לכם טבעות עם רובי עד 1500 ש"ח', self::carried('מה יש לכם עד 1500 ש״ח?', ['יש לכם טבעות עם רובי?']));
        // "אשמח לראות" says nothing of its own: the question before it does
        $this->assertSame('יש רובי בצבעים שונים עד 1500 ש"ח', self::carried('ומה יש עד 1500?', ['מאיפה מוצאים רובי?', 'יש רובי בצבעים שונים?', 'אשמח לראות']));
        $this->assertSame('do you have running shoes under 80', self::carried('anything under 80?', ['do you have running shoes?']));
    }

    public function test_a_reply_that_names_more_than_a_price_or_no_price_stays(): void
    {
        $this->assertNull(self::carried('יש לכם תיקים עד 500 ש״ח?', ['מתנה לאשתי']), 'it names its own kind');
        $this->assertNull(self::carried('ומה בצבע שחור?', ['יש לכם קפוצ׳ונים?']), 'no price');
        $this->assertNull(self::carried('מה יש עד 1500?', []), 'no conversation');
        $this->assertNull(self::carried('מה יש עד 1500?', ['אשמח לראות']), 'nothing before has words of its own');
    }
}
