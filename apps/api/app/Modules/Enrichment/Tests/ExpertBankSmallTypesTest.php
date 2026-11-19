<?php

namespace App\Modules\Enrichment\Tests;

use App\Modules\Enrichment\Scanning\ExpertBankGate;
use App\Modules\Enrichment\Scanning\ExpertBankShape;
use App\Modules\Enrichment\Scanning\PatternMatch;
use PHPUnit\Framework\TestCase;

/**
 * Vans, 19.11: five sandals and six accessories were left with no type. The expert wrote "חגורות" and "כפכפים" and the
 * titles say "חגורה" and "כפכפי סלייד", so code found no evidence; and the checker dropped the small types for being few.
 * Any store.
 */
final class ExpertBankSmallTypesTest extends TestCase
{
    public function test_a_hebrew_plural_reads_its_singular_and_construct_too(): void
    {
        $reads = fn (string $word, string $text): bool => PatternMatch::all($text, ExpertBankShape::pattern($word)) !== [];

        $this->assertTrue($reads('חגורות', 'חגורה Deppster II Web'));
        $this->assertTrue($reads('כפכפים', 'כפכפי סלייד La Costa'));
        $this->assertTrue($reads('כפכפים', 'כפכף אצבע'));
        $this->assertTrue($reads('מכנסיים', 'מכנס ג\'ינס'));
        $this->assertTrue($reads('שמלות', 'שמלה קצרה'));
        $this->assertTrue($reads('ארנקים', 'ארנק Off The Wall Zip'));
        $this->assertTrue($reads('חגורות', 'חגורות עור'), 'the plural itself still');
        $this->assertFalse($reads('ספרות', 'ספר בישול'), 'literature is no book');
        $this->assertFalse($reads('חגורות', 'חגורתי'), 'nor another word');
    }

    public function test_a_type_the_checker_scored_down_for_being_few_stays_while_its_products_would_have_none(): void
    {
        $bank = ['types' => [
            ['id' => 'type:sandals', 'key' => 'sandals', 'label' => 'כפכפים וסנדלים', 'words' => ['כפכפים']],
            ['id' => 'type:belt', 'key' => 'belt', 'label' => 'חגורות', 'words' => ['חגורות']],
            ['id' => 'type:wrong', 'key' => 'wrong', 'label' => 'טבעות', 'words' => ['טבעות']],
            ['id' => 'type:inside', 'key' => 'inside', 'label' => 'סניקרס נמוכות', 'words' => ['סניקרס נמוכות']],
        ], 'attributes' => []];
        $verdicts = [
            'type:sandals' => ['score' => 60, 'verdict' => 'keep', 'reason' => 'niche', 'kept' => false, 'why' => 'low_score'],
            'type:belt' => ['score' => 55, 'verdict' => 'keep', 'reason' => 'few', 'kept' => false, 'why' => 'low_score'],
            'type:wrong' => ['score' => 30, 'verdict' => 'drop', 'reason' => 'too rare', 'kept' => false, 'why' => 'checker_drop'],
            'type:inside' => ['score' => 60, 'verdict' => 'keep', 'reason' => 'overlaps', 'kept' => false, 'why' => 'low_score'],
        ];
        $measured = ['types' => ['sandals' => ['alone' => 5], 'belt' => ['alone' => 2], 'wrong' => ['alone' => 3], 'inside' => ['alone' => 0]]];

        [$kept, $removed] = ExpertBankGate::checked($bank, $verdicts, 'check', $measured);

        $this->assertSame(['sandals', 'belt'], array_column($kept['types'], 'key'));
        $this->assertSame(['type:wrong', 'type:inside'], array_column($removed, 'id'), 'one the checker dropped goes, and one whose products another type reads');

        [$none] = ExpertBankGate::checked($bank, $verdicts);
        $this->assertSame([], $none['types'], 'with nothing measured, the checker decides as before');
    }
}
