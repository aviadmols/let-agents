<?php

namespace App\Modules\Assistant\Tests;

use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use PHPUnit\Framework\TestCase;

/** The page an answer rests on that the question names becomes a button to it; a page it only mentions does not. */
final class NamedPageTest extends TestCase
{
    public function test_the_page_whose_title_the_question_names(): void
    {
        $sources = [
            ['title' => 'מה שהפוסטים והמאמרים באתר אומרים על החנות', 'url' => null, 'type' => 'content'],
            ['title' => 'מנויים', 'url' => 'https://sellameir.test/subscriptions', 'type' => 'content'],
        ];
        $this->assertSame(['title' => 'מנויים', 'url' => 'https://sellameir.test/subscriptions'], AnswerSiteQuestion::namedPage('מה זה תוכנית מנויים?', $sources));

        $policy = [['title' => 'Refund policy', 'url' => 'https://r.test/policies/refund-policy', 'type' => 'content'], ['title' => 'מדיניות החזרות', 'url' => 'https://r.test/policies/refund-policy', 'type' => 'content']];
        $this->assertSame('מדיניות החזרות', AnswerSiteQuestion::namedPage('מה מדיניות ההחזרות?', $policy)['title'] ?? null, 'a prefix letter before the word is still the word');

        $this->assertNull(AnswerSiteQuestion::namedPage('כמה זמן לוקח משלוח?', $policy), 'a page the question does not name: no button');
        $this->assertNull(AnswerSiteQuestion::namedPage('מה זה מנויים?', [['title' => 'מנויים', 'url' => 'https://x.test/p/1', 'type' => 'product']]), 'a product is not a page');
    }
}
