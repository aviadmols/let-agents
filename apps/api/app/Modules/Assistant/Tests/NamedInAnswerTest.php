<?php

namespace App\Modules\Assistant\Tests;

use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use PHPUnit\Framework\TestCase;

/**
 * A product the answer names is shown under it as a pick, with the sentence that names it, from the products the
 * writer was given only: quoted, or whole when its name is no common word; never inside another word.
 */
final class NamedInAnswerTest extends TestCase
{
    private const PRODUCTS = [
        'p1' => ['external_id' => '11', 'title' => 'לו - היסטוריה חלופית לישראל'],
        'p2' => ['external_id' => '12', 'title' => 'דרקולה'],
        'p3' => ['external_id' => '13', 'title' => 'אור'],
        'p4' => ['external_id' => '14', 'title' => 'שמע ישמעאל'],
        'p5' => ['external_id' => '15', 'title' => 'הרוזן ממונטה כריסטו - מהדורה מלאה'],
    ];

    public function test_a_product_named_in_the_answer_is_a_pick_with_its_sentence(): void
    {
        $answer = 'אין כאן מדע בדיוני קלאסי, אבל "לו - היסטוריה חלופית לישראל" מדמיין ישראל אחרת. ואם אתם אוהבים מתח, "דרקולה" הוא קלאסיקה שתשאב אתכם.';
        $picks = [['ref' => 'p1', 'external_id' => '11', 'title' => 'לו - היסטוריה חלופית לישראל', 'why' => 'מדמיין ישראל אחרת']];

        $named = AnswerSiteQuestion::namedInAnswer($answer, $picks, self::PRODUCTS);

        $this->assertSame(['p1', 'p2'], array_column($named, 'ref'), 'the writer\'s pick first, then the one the answer names');
        $this->assertSame('ואם אתם אוהבים מתח, "דרקולה" הוא קלאסיקה שתשאב אתכם.', $named[1]['why']);
    }

    public function test_a_short_common_name_counts_only_in_quotes_never_inside_a_word(): void
    {
        $this->assertSame([], AnswerSiteQuestion::namedInAnswer('הספר מביא אור חדש על התקופה, ומאיר אותה היטב.', [], self::PRODUCTS), '"אור" unquoted is a word, not the book');
        $this->assertSame(['p3'], array_column(AnswerSiteQuestion::namedInAnswer('נסו את "אור", ספר שירה עדין.', [], self::PRODUCTS), 'ref'));
    }

    public function test_a_long_name_counts_whole_after_a_prefix_letter_and_the_picks_stay_three(): void
    {
        $answer = 'בשמע ישמעאל תמצאו מסע אישי. גם הרוזן ממונטה כריסטו מתאים, וכך גם "דרקולה" ו"לו - היסטוריה חלופית לישראל".';

        $named = AnswerSiteQuestion::namedInAnswer($answer, [], self::PRODUCTS);

        $this->assertSame(['p4', 'p5', 'p2'], array_column($named, 'ref'), 'in the order the answer names them, at most three');
    }
}
