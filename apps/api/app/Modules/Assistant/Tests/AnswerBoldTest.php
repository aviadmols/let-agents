<?php

namespace App\Modules\Assistant\Tests;

use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use PHPUnit\Framework\TestCase;

/**
 * Bold in an answer (as Aviad asked, 19.11): where it is shown, the few phrases the writer marked are kept as "**…**", at
 * most four, each on one line; a star left alone goes; off, every mark goes as before.
 */
final class AnswerBoldTest extends TestCase
{
    public function test_bold_pairs_are_kept_where_bold_is_shown_and_go_where_it_is_not(): void
    {
        $answer = "**כן, חדשים** ומיוצרים אצלנו.\n- **15.62 קראט** של יהלומים\n- __זהב לבן 14K__ במשקל 14.15 גרם";

        $this->assertSame("**כן, חדשים** ומיוצרים אצלנו.\n- **15.62 קראט** של יהלומים\n- **זהב לבן 14K** במשקל 14.15 גרם", AnswerSiteQuestion::withoutMarkdown($answer, true));
        $this->assertSame("כן, חדשים ומיוצרים אצלנו.\n- 15.62 קראט של יהלומים\n- זהב לבן 14K במשקל 14.15 גרם", AnswerSiteQuestion::withoutMarkdown($answer));
    }

    public function test_no_more_than_four_and_a_lone_star_goes(): void
    {
        $out = AnswerSiteQuestion::withoutMarkdown('**א** **ב** **ג** **ד** **ה** ו** ז', true);

        $this->assertSame('**א** **ב** **ג** **ד** ה ו ז', $out);
    }
}
