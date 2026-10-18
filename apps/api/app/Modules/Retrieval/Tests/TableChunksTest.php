<?php

namespace App\Modules\Retrieval\Tests;

use App\Modules\Retrieval\Support\Chunker;
use PHPUnit\Framework\TestCase;

/** A table in a document is cut into pieces between its rows, each piece with the table's header row. */
final class TableChunksTest extends TestCase
{
    public function test_a_long_table_is_cut_between_rows_and_each_piece_repeats_its_header(): void
    {
        $rows = array_map(fn (int $mm): string => "| {$mm} מ\"מ | שימוש מתאים לעובי של {$mm} מילימטר בבנייה |", range(1, 30));
        $text = "פתיחה קצרה.\n| עובי | שימוש |\n| --- | --- |\n".implode("\n", $rows)."\nסוף.";

        $pieces = Chunker::split('לוחות', $text, 600);

        $tablePieces = array_values(array_filter($pieces, fn (string $p): bool => str_contains($p, '| 1') || str_contains($p, 'מילימטר')));
        $this->assertGreaterThan(1, count($tablePieces), 'the table did not fit one piece');
        foreach ($tablePieces as $piece) {
            $this->assertStringContainsString("| עובי | שימוש |\n| --- | --- |", $piece, 'every piece says what its columns mean');
            $this->assertLessThanOrEqual(600, mb_strlen($piece));
        }
        $this->assertSame(30, substr_count(implode("\n", $pieces), 'מילימטר בבנייה'), 'no row is lost or doubled');
    }
}
