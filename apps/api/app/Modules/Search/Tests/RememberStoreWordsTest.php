<?php

namespace App\Modules\Search\Tests;

use App\Modules\Search\Actions\RememberStoreWords;
use PHPUnit\Framework\TestCase;

/** The store's words by meaning: its filters' values, and the words some of its products say and not most. */
final class RememberStoreWordsTest extends TestCase
{
    public function test_the_words_some_products_say_not_one_and_not_most_with_the_filters_first(): void
    {
        $items = [
            ['t' => 'product', 'title' => 'חולצה מכופתרת Striped', 'kw' => 'פסים כיס חזה'],
            ['t' => 'product', 'title' => 'חולצה מכופתרת Basic', 'kw' => 'פסים צווארון'],
            ['t' => 'product', 'title' => 'חולצה Plain', 'kw' => 'כיס'],
            ['t' => 'product', 'title' => 'חולצה Tee', 'kw' => 'רקמה'],
            ['t' => 'category', 'title' => 'פסים פסים', 'kw' => ''],
        ];
        $facets = [['k' => 'variant_color', 'l' => 'צבע', 'v' => [['l' => 'לבן'], ['l' => 'ירוק']]]];

        $words = RememberStoreWords::vocabulary($items, $facets, 10);

        $this->assertSame(['לבן', 'ירוק'], array_slice($words, 0, 2), 'the filters\' values first');
        foreach (['פסים', 'כיס', 'מכופתרת'] as $word) {
            $this->assertContains($word, $words, 'said by two of four');
        }
        $this->assertNotContains('חולצה', $words, 'said by all: the store\'s subject, no word to tell apart');
        $this->assertNotContains('רקמה', $words, 'said by one only');
        $this->assertCount(3, RememberStoreWords::vocabulary($items, $facets, 3), 'at most as many as the store keeps');
    }
}
