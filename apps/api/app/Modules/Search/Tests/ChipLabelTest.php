<?php

namespace App\Modules\Search\Tests;

use App\Modules\Search\Actions\BuildSearchIndex;
use PHPUnit\Framework\TestCase;

/** A tag's name as a filter: two words a model joined with a hyphen are two words; a letter alone keeps it (Gil Travel, 19.11). */
final class ChipLabelTest extends TestCase
{
    public function test_two_words_joined_by_a_hyphen_read_as_two_words(): void
    {
        $this->assertSame('Shabbat friendly', BuildSearchIndex::chipLabel('Shabbat-friendly'));
        $this->assertSame('T-shirt', BuildSearchIndex::chipLabel('T-shirt'));
        $this->assertSame('X-Large', BuildSearchIndex::chipLabel('X-Large'));
        $this->assertSame('ידידותי לשבת', BuildSearchIndex::chipLabel('ידידותי לשבת'));
        $this->assertSame('10-14 days', BuildSearchIndex::chipLabel('10-14 days'));
    }
}
