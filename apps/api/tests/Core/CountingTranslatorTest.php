<?php

namespace Tests\Core;

use App\Core\Localization\CountingTranslator;
use App\Core\Localization\Locales;
use Tests\TestCase;

/**
 * "1 searches" was on the merchant's screens, "1 actions · 1 pages" too: a line that counts several things
 * gets each word agreeing with its own number ("{n:search/searches}"), in Hebrew as in English.
 */
final class CountingTranslatorTest extends TestCase
{
    public function test_each_word_agrees_with_its_own_number(): void
    {
        $this->assertInstanceOf(CountingTranslator::class, app('translator'));

        $line = ':steps {steps:action/actions} · :pages {pages:page/pages}';
        $this->assertSame('1 action · 3 pages', __($line, ['steps' => 1, 'pages' => 3]));
        $this->assertSame('2 actions · 1 page', __($line, ['steps' => '2', 'pages' => '1']));
        $this->assertSame('1,129 searches', __(':n {n:search/searches}', ['n' => '1,129']), 'a number as the code formats it');
        $this->assertSame('0 searches', __(':n {n:search/searches}', ['n' => 0]));
        $this->assertSame('חיפוש 1', __('{n:חיפוש/חיפושים} :n', ['n' => 1]));
    }

    public function test_a_block_without_its_number_is_left_as_written(): void
    {
        $this->assertSame('{n:search/searches}', __('{n:search/searches}', ['other' => 1]));
        $this->assertSame('x {n:a/b}', __('x {n:a/b}', ['n' => 'many']));
        // trans_choice's own ranges are untouched.
        $this->assertSame('3 sign-ups', trans_choice('{1} one sign-up|[2,*] :count sign-ups', 3, ['count' => 3]));
    }

    public function test_the_modules_lines_count_in_both_languages(): void
    {
        $this->assertSame('1 search', __('analytics::sessions.sum.searches', ['n' => 1], 'en'));
        $this->assertSame('1 action · 1 page', __('analytics::sessions.row.steps', ['steps' => 1, 'pages' => 1], 'en'));
        $this->assertStringContainsString('1 category, 1 article.', __('catalog::runs.synced', ['products' => 1, 'created' => 0, 'updated' => 0, 'removed' => 0, 'categories' => 1, 'content' => 1], 'en'));
        $this->assertSame('Search index: 1 product, 1 guide or page, 2 categories.', __('search::runs.indexed', ['products' => 1, 'content' => 1, 'categories' => 2], 'en'));
        $this->assertSame('1 חיפוש', __('analytics::sessions.sum.searches', ['n' => 1], 'he'));
        $this->assertSame('3 חיפושים', __('analytics::sessions.sum.searches', ['n' => 3], 'he'));
    }

    public function test_a_shops_built_in_words_are_in_its_language_or_english(): void
    {
        $this->assertSame('he', Locales::forShop('he'));
        $this->assertSame('he', Locales::forShop(null), 'a shop that never said');
        $this->assertSame('en', Locales::forShop('en-US'));
        $this->assertSame('en', Locales::forShop('fr'), 'never Hebrew for a store that is not');
        $this->assertSame('en', Locales::forShop('ar'));
    }
}
