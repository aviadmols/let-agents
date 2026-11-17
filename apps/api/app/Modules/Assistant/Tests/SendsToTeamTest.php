<?php

namespace App\Modules\Assistant\Tests;

use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use PHPUnit\Framework\TestCase;

/**
 * An answer that hands the shopper to the team, in any words, always has the way to them under it (WhatsApp, a phone or an
 * email left): "צוות שירות הלקוחות שלנו ישמח לעמוד לרשותכם" had nothing under it (Take Me Home, 17.11).
 */
final class SendsToTeamTest extends TestCase
{
    public function test_an_answer_to_the_team_in_any_words_says_so_and_a_plain_answer_does_not(): void
    {
        foreach ([
            'לגבי הזמנות אונליין, לא מצאנו פרטים על כך באתר; צוות שירות הלקוחות שלנו ישמח לעמוד לרשותכם.',
            'צוות החנות ישמח לעזור לכם.',
            'אפשר ליצור איתנו קשר בוואטסאפ.',
            'לא מצאנו מידע על המידות באתר.',
            'אורך השרשרת לא מצוין בעמוד.',
            'Our team will be glad to help you.',
            'We could not find the length on the site.',
        ] as $answer) {
            $this->assertTrue(AnswerSiteQuestion::sendsToTeam($answer), $answer);
        }
        foreach ([
            'אפשר להחזיר או להחליף בכל סניף של סופר-פארם, עם החשבונית.',
            'הצוות שלנו מכין כל מארז ביד, באהבה.',
            'The ring is 14K gold.',
            '',
        ] as $answer) {
            $this->assertFalse(AnswerSiteQuestion::sendsToTeam($answer), $answer);
        }
    }
}
