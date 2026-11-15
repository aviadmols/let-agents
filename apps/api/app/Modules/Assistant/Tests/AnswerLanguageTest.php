<?php

namespace App\Modules\Assistant\Tests;

use App\Modules\Assistant\Support\AnswerLanguage;
use PHPUnit\Framework\TestCase;

/** The shopper's language: the question's own, then the storefront's, then the shop's; English, never Hebrew, by default. */
final class AnswerLanguageTest extends TestCase
{
    public function test_the_questions_own_language_comes_first(): void
    {
        $this->assertSame('en', AnswerLanguage::of('Which snowboard is good for a beginner?', 'he', 'he'), 'an English question on a Hebrew storefront');
        $this->assertSame('he', AnswerLanguage::of('איזה סנובורד מתאים למתחילים?', 'en', 'en'));
        $this->assertSame('he', AnswerLanguage::of('יש לכם Makita DHP 18V?', 'en'), 'a brand in Latin inside Hebrew');
    }

    public function test_then_the_storefront_then_the_shop_then_english(): void
    {
        $this->assertSame('en', AnswerLanguage::of('59?', 'en-US', 'he'));
        $this->assertSame('he', AnswerLanguage::of('59?', null, 'he'));
        $this->assertSame('en', AnswerLanguage::of('59?', null, null), 'never Hebrew by default');
        $this->assertSame('en', AnswerLanguage::of('59?', 'fr', ''));
    }

    public function test_whether_a_text_is_written_in_a_language(): void
    {
        $this->assertTrue(AnswerLanguage::writtenIn('The Burton Custom is a great first board.', 'en'));
        $this->assertFalse(AnswerLanguage::writtenIn('ה-Burton Custom הוא קרש מצוין למתחילים.', 'en'));
        $this->assertTrue(AnswerLanguage::writtenIn('ה-Burton Custom הוא קרש מצוין למתחילים.', 'he'), 'a name in Latin among Hebrew');
        $this->assertFalse(AnswerLanguage::writtenIn('The Burton Custom is a great first board.', 'he'));
        $this->assertTrue(AnswerLanguage::writtenIn('46–50', 'en'), 'no letters: any language');
        $this->assertSame(['Hebrew', 'English'], [AnswerLanguage::name('he'), AnswerLanguage::name('en')]);
    }
}
