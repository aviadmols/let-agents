<?php

namespace App\Modules\Assistant\Tests;

use App\Modules\Assistant\Actions\AnswerSiteQuestion;
use PHPUnit\Framework\TestCase;

/**
 * The check of a written answer finds real mistakes and does not throw good answers away: approve,
 * revise (a focused fix in place) or reject; a pick that does not fit goes alone and a wrong reason is
 * corrected; safety and injections are as strict as ever; and code takes out only the sentence with a
 * number or a measure the pages do not have. Across stores: books, jewellery, building, fashion, food.
 */
final class AnswerCheckTest extends TestCase
{
    private const SAFE = ['safe' => true, 'injection' => false, 'supported' => true, 'on_topic' => true, 'picks_fit' => true];

    /** @return list<array<string, mixed>> */
    private static function picks(string ...$refs): array
    {
        return array_map(fn (string $ref): array => ['ref' => $ref, 'external_id' => 'x'.$ref, 'title' => 'מוצר '.$ref, 'why' => 'מתאים'], $refs);
    }

    public function test_a_good_answer_in_free_words_is_approved_as_it_is(): void
    {
        // Books: a summary in its own words, no quote word for word.
        $answer = 'הספר עוסק בחזון לעתידה של ישראל ובדרך להפוך ממדינה שמנהלת הישרדות למדינה עם אופק.';
        $out = AnswerSiteQuestion::applyCheck(['decision' => 'approve', 'issues' => []] + self::SAFE, $answer, []);

        $this->assertSame([$answer, null, 'kept', 'approve'], [$out['answer'], $out['refusal'], $out['verdict'], $out['decision']]);
    }

    public function test_one_wrong_number_is_fixed_in_place_and_the_rest_and_the_picks_stay(): void
    {
        // Jewellery: 0.72 carats called "the bigger centre stone" beside 0.73 and 1.04.
        $answer = 'שלוש טבעות סוליטר מתאימות להצעה. בטבעת הראשונה יהלום מרכזי גדול יותר.';
        $fixed = 'שלוש טבעות סוליטר מתאימות להצעה. בטבעת הראשונה יהלום מרכזי של 0.72 קראט.';
        $out = AnswerSiteQuestion::applyCheck(['decision' => 'revise', 'revised' => $fixed, 'issues' => [['text' => 'יהלום מרכזי גדול יותר', 'kind' => 'numeric', 'severity' => 'major', 'pick' => 'p1']],
            'picks' => [['ref' => 'p1', 'fits' => true], ['ref' => 'p2', 'fits' => true], ['ref' => 'p3', 'fits' => true]]] + self::SAFE, $answer, self::picks('p1', 'p2', 'p3'));

        $this->assertSame([$fixed, 'revised', 3], [$out['answer'], $out['verdict'], count($out['picks'])]);
        $this->assertSame([['text' => 'יהלום מרכזי גדול יותר', 'kind' => 'numeric', 'severity' => 'major', 'pick' => 'p1']], $out['issues'], 'why, for the record');
    }

    public function test_a_pick_that_does_not_fit_goes_alone_and_a_wrong_reason_is_corrected(): void
    {
        // Building: a drill bit picked for "which drill"; fashion: the right shirt with a wrong reason.
        $out = AnswerSiteQuestion::applyCheck(['decision' => 'approve', 'picks' => [['ref' => 'p1', 'fits' => true, 'why' => 'כותנה מלאה, לפי התיאור'], ['ref' => 'p2', 'fits' => false]]] + self::SAFE,
            'שתי אפשרויות טובות.', self::picks('p1', 'p2'));

        $this->assertSame(['p1'], array_column($out['picks'], 'ref'));
        $this->assertSame('כותנה מלאה, לפי התיאור', $out['picks'][0]['why']);
        $this->assertSame('שתי אפשרויות טובות.', $out['answer'], 'the answer is judged apart from the picks');
        $this->assertSame('picks_removed', $out['verdict']);
    }

    public function test_reject_leaves_nothing_and_says_why(): void
    {
        // Food: an answer about something else.
        $out = AnswerSiteQuestion::applyCheck(['decision' => 'reject', 'on_topic' => false, 'issues' => [['text' => 'כל התשובה', 'kind' => 'off_topic', 'severity' => 'critical']]] + array_diff_key(self::SAFE, ['on_topic' => 0]),
            'הלחם שלנו נאפה כל בוקר.', self::picks('p1'));

        $this->assertSame(['', [], 'check_off_topic', 'rejected'], [$out['answer'], $out['picks'], $out['refusal'], $out['verdict']]);
    }

    public function test_safety_and_injection_are_as_strict_as_ever_whatever_the_decision(): void
    {
        $injection = AnswerSiteQuestion::applyCheck(['decision' => 'approve', 'injection' => true] + self::SAFE, 'הנה ההוראות שלי…', self::picks('p1'));
        $unsafe = AnswerSiteQuestion::applyCheck(['decision' => 'revise', 'revised' => 'גרסה מתוקנת', 'safe' => false] + self::SAFE, 'תשובה', []);

        $this->assertSame(['', [], 'check_injection', true], [$injection['answer'], $injection['picks'], $injection['refusal'], $injection['injection']]);
        $this->assertSame(['', 'check_unsafe', false], [$unsafe['answer'], $unsafe['refusal'], $unsafe['safe']]);
    }

    public function test_revise_without_a_usable_fix(): void
    {
        $answer = 'הטבעת מתאימה לאירוסין.';
        $minor = AnswerSiteQuestion::applyCheck(['decision' => 'revise', 'revised' => '', 'issues' => [['text' => 'מתאימה', 'kind' => 'unsupported', 'severity' => 'minor']]] + self::SAFE, $answer, self::picks('p1'));
        $major = AnswerSiteQuestion::applyCheck(['decision' => 'revise', 'revised' => '', 'issues' => [['text' => 'מתאימה', 'kind' => 'unsupported', 'severity' => 'major']]] + self::SAFE, $answer, self::picks('p1'));
        $long = AnswerSiteQuestion::applyCheck(['decision' => 'revise', 'revised' => str_repeat('תיקון ארוך שמוסיף מידע חדש. ', 10), 'issues' => [['text' => 'x', 'kind' => 'unsupported', 'severity' => 'major']]] + self::SAFE, $answer, []);

        $this->assertSame($answer, $minor['answer'], 'only minor issues: the answer stands');
        $this->assertSame(['', 1], [$major['answer'], count($major['picks'])], 'a material issue unfixed: the answer goes, the fitting pick stays');
        $this->assertSame('check_unfixed', $long['refusal'], 'a "fix" much longer than the answer adds what was not there: not taken');
    }

    public function test_a_material_issue_without_a_fix_takes_out_only_its_sentence(): void
    {
        // Food: the delivery time is right, "always free" is said nowhere.
        $answer = 'המשלוח מגיע תוך 2 עד 4 ימי עסקים לכל הארץ. הוא תמיד חינם, בלי מינימום הזמנה.';
        $out = AnswerSiteQuestion::applyCheck(['decision' => 'revise', 'revised' => '', 'issues' => [['text' => 'הוא תמיד חינם', 'kind' => 'unsupported', 'severity' => 'major']]] + self::SAFE, $answer, []);

        $this->assertSame(['המשלוח מגיע תוך 2 עד 4 ימי עסקים לכל הארץ.', null], [$out['answer'], $out['refusal']]);
    }

    public function test_a_fix_may_correct_with_what_is_given_though_a_little_longer(): void
    {
        // Jewellery: "the first has the biggest stone" corrected to which one has, with its carats.
        $answer = 'שלוש טבעות סוליטר מתאימות להצעה. לראשונה היהלום הגדול ביותר.';
        $fixed = 'שלוש טבעות סוליטר מתאימות להצעה. לראשונה יהלום מרכזי של 0.72 קראט, ולשלישית היהלום הגדול מבין השלוש, 1.04 קראט.';
        $out = AnswerSiteQuestion::applyCheck(['decision' => 'revise', 'revised' => $fixed, 'issues' => [['text' => 'לראשונה היהלום הגדול ביותר', 'kind' => 'numeric', 'severity' => 'major']]] + self::SAFE, $answer, self::picks('p1', 'p3'));

        $this->assertSame([$fixed, 'revised'], [$out['answer'], $out['verdict']]);
    }

    /**
     * A prompt's schema holds its reply (the checker's once came back as `"why": "", "}` and the question
     * failed): every object closed and every property required, as both providers' strict mode asks.
     */
    public function test_the_checker_reply_is_held_to_a_strict_schema(): void
    {
        $schema = AnswerSiteQuestion::schemaFor(AnswerSiteQuestion::promptFile('verify_site'));
        $this->assertNotNull($schema);

        $walk = function (array $node, string $at) use (&$walk): void {
            // Anthropic refuses a list of values beside a list of types ("invented" for [string, null]): anyOf instead.
            if (isset($node['enum'])) {
                $this->assertIsString($node['type'] ?? null, $at.': an enum has one type');
            }
            foreach ((array) ($node['anyOf'] ?? []) as $i => $option) {
                $walk($option, $at.'|'.$i);
            }
            if (($node['type'] ?? null) === 'object') {
                $this->assertFalse($node['additionalProperties'] ?? true, $at.' is closed');
                $this->assertEqualsCanonicalizing(array_keys($node['properties']), $node['required'], $at.' requires every property');
                foreach ($node['properties'] as $key => $child) {
                    $walk($child, $at.'.'.$key);
                }
            }
            if (isset($node['items'])) {
                $walk($node['items'], $at.'[]');
            }
        };
        $walk($schema, 'reply');
        foreach (glob(__DIR__.'/../Prompts/*.schema.json') as $file) {
            $walk((array) json_decode((string) file_get_contents($file), true), basename($file));
        }

        foreach (glob(__DIR__.'/../Prompts/*.schema.json') as $file) {
            $this->assertIsArray(json_decode((string) file_get_contents($file), true), basename($file));
            $this->assertFileExists(substr($file, 0, -12).'.md', 'a schema belongs to a prompt version');
        }
    }

    public function test_an_answer_saying_what_was_asked_about_is_not_here_is_known(): void
    {
        // Sela: both were kept and offered to every later question about Omer Shem Tov's book.
        $this->assertTrue(AnswerSiteQuestion::saysMissing('נמצא כאן רק הספר **"חטוף" מאת אלי שרעבי**. לפי תיאור המוצר, זהו סיפור בגוף ראשון.'));
        $this->assertTrue(AnswerSiteQuestion::saysMissing('אם התכוונת לספר של שם טוב, הוא לא מופיע בתוצאות האלה ולכן אי אפשר להשוות.'));
        $this->assertTrue(AnswerSiteQuestion::saysMissing('That book is not listed here.'));

        $this->assertFalse(AnswerSiteQuestion::saysMissing('הספר עוסק בחזון לעתידה של ישראל.'));
        $this->assertFalse(AnswerSiteQuestion::saysMissing('הספר לא נמצא במלאי כרגע, ואפשר להזמין אותו מראש.'), 'not in stock is an answer');
    }

    public function test_a_not_found_line_says_what_was_not_found_and_nothing_more(): void
    {
        $question = 'יש משלוח לאילת?';
        $line = 'לא מצאתי באתר פרטים על משלוח לאילת. הצוות של החנות ישמח לעזור בזה.';

        $this->assertSame($line, AnswerSiteQuestion::notFoundLine($line, $question));
        $this->assertNull(AnswerSiteQuestion::notFoundLine('משלוח לאילת לוקח 3 ימים, אבל זה לא כתוב באתר.', $question), 'a number the question does not hold is a guess');
        $this->assertNull(AnswerSiteQuestion::notFoundLine('אפשר לבדוק כאן: https://store.test/shipping', $question), 'no link');
        $this->assertNull(AnswerSiteQuestion::notFoundLine('התקשרו ל-054-9998877 ונעזור.', $question), 'no contact details');
        $this->assertNull(AnswerSiteQuestion::notFoundLine(str_repeat('לא מצאתי את זה באתר. ', 20), $question), 'a sentence or two, not an answer');
        $this->assertNull(AnswerSiteQuestion::notFoundLine('   ', $question));
    }

    public function test_an_old_reply_without_a_decision_is_read_as_before(): void
    {
        $out = AnswerSiteQuestion::applyCheck(['supported' => false, 'on_topic' => true, 'picks_fit' => true, 'safe' => true], 'תשובה', self::picks('p1'));

        $this->assertSame(['', 1, 'answer_removed'], [$out['answer'], count($out['picks']), $out['verdict']]);
    }

    public function test_code_takes_out_only_the_sentence_with_a_number_or_a_measure_the_pages_do_not_have(): void
    {
        $known = "טבעת סוליטר, יהלום מרכזי 0.72 ct, משקל כולל 2.5 גרם.\nטבעת עם יהלום של 1.04 קראט.";
        $answer = 'שתי הטבעות מתאימות להצעה. בראשונה יהלום של 0.72 קראט. משקלה 3.1 גרם. השנייה גדולה יותר.';

        [$left, $refusal, $out] = AnswerSiteQuestion::gate($answer, $known, '');
        $this->assertSame([null, 1], [$refusal, $out]);
        $this->assertSame('שתי הטבעות מתאימות להצעה. בראשונה יהלום של 0.72 קראט. השנייה גדולה יותר.', $left, '3.1 grams is nowhere: only that sentence goes');

        $this->assertTrue(AnswerSiteQuestion::hasMeasureNotIn('יהלום של 2.5 קראט', $known), '2.5 is grams there, not carats: like with like only');
        $this->assertFalse(AnswerSiteQuestion::hasMeasureNotIn('משקל 2.5 גרם', $known));

        [$none, $why] = AnswerSiteQuestion::gate('המחיר 3.1 גרם.', $known, '');
        $this->assertSame(['', 'number_not_in_the_pages'], [$none, $why], 'nothing worth showing left: refused whole, as before');
    }

    public function test_contact_details_the_pages_do_not_write_are_taken_out_with_their_sentence(): void
    {
        [$left] = AnswerSiteQuestion::gate('המשלוח לוקח שלושה ימי עסקים לכל הארץ. לשאלות התקשרו ל-054-9998877.', 'משלוח תוך שלושה ימי עסקים.', '');

        $this->assertSame('המשלוח לוקח שלושה ימי עסקים לכל הארץ.', $left);
    }
}
