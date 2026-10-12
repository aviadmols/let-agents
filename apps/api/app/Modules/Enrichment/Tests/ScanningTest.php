<?php

namespace App\Modules\Enrichment\Tests;

use App\Modules\Enrichment\Scanning\Boilerplate;
use App\Modules\Enrichment\Scanning\Measurement;
use App\Modules\Enrichment\Scanning\MeasurementScanner;
use App\Modules\Enrichment\Scanning\NumberParser;
use App\Modules\Enrichment\Scanning\TextCondenser;
use App\Modules\Enrichment\Scanning\TextNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Code reads numbers and text before any model does. These are real sentences from the pilot
 * store, in the forms that broke naive parsing.
 */
final class ScanningTest extends TestCase
{
    /** @return array<string, array{string, float|null}> */
    public static function numbers(): array
    {
        return [
            'thousands' => ['2,800', 2800.0],
            'decimal point' => ['1.5', 1.5],
            'decimal comma' => ['2,5', 2.5],
            'mixed inch fraction with dash' => ['7-1/4', 7.25],
            'mixed inch fraction with dot' => ['7.1/4', 7.25],
            'fraction alone' => ['1/4', 0.25],
            'unicode half after' => ['4½', 4.5],
            'unicode half before, as right-to-left shows it' => ['½7', 7.5],
            'not a fraction of an inch' => ['15.55/18', null],
            'text' => ['abc', null],
        ];
    }

    #[DataProvider('numbers')]
    public function test_numbers_are_read_the_way_stores_write_them(string $raw, ?float $expected): void
    {
        $this->assertSame($expected, NumberParser::parse($raw));
    }

    /** @return array<string, array{string, list<array{string, float|int, string}>}> */
    public static function sentences(): array
    {
        return [
            'inch mark before the number, right to left' => ['גוף מסור עגול לעץ נטען "½6 Makita 18V', [['length', 165.1, 'mm'], ['voltage', 18, 'V']]],
            'inch fraction after the mark' => ['מסור פנדל "7-1/2 נטען STANLEY', [['length', 190.5, 'mm']]],
            'curly inch mark then millimeters' => ['משחזת זוית ”9 230 מ“מ STANLEY FATMAX 2200W', [['length', 228.6, 'mm'], ['length', 230, 'mm'], ['power', 2200, 'W']]],
            'two batteries or a 36 V tool: both offered' => ['מסור פנדל נטען DLS714 2X18V', [['voltage', 36, 'V'], ['voltage', 18, 'V']]],
            'watts after a slash' => ['הספק מנוע: 2.5HP/1800W', [['power', 1800, 'W']]],
            'milliamp hours converted' => ['מברגת אימפקט נטענת 18V/2000mAh', [['voltage', 18, 'V'], ['charge', 2, 'Ah']]],
            'speed range takes the top' => ['מהירות סיבוב: 0–2,800 סל"ד', [['speed', 2800, 'rpm']]],
            'two apostrophes as gershayim' => ['קוטר דיסק 230 מ’’מ, מהירות 6500 סל’’ד', [['length', 230, 'mm'], ['speed', 6500, 'rpm']]],
            'weight written as two numbers is not a fraction' => ['משקל: 15.55/18 ק"ג', []],
            'model names are not measurements' => ['מקיטה DTD153 סדרת V20', []],
            'a quoted phrase is not inches' => ['"10 מקדחים" במארז', []],
            'impact rate in Hebrew' => ['עד כ‑3,800 פעימות לדקה', [['rate', 3800, 'bpm']]],
            'a stone\x27s weight in carats' => ['טבעת וינטג\' משובצת רובי במשקל 0.31 קראט ויהלומים', [['carat', 0.31, 'ct']]],
            'a jewel weighed in grams' => ['עגילים זהב צהוב 14 קארט, משקל התכשיט 1.6 גרם', [['mass', 0.0016, 'kg']]],
            'gold\x27s karat spelled קארט is not a weight' => ['טבעת זהב 18 קארט', []],
            'torque with non-breaking space' => ["מומנט מירבי: 110\u{00A0}Nm", [['torque', 110, 'Nm']]],
        ];
    }

    /** @param list<array{string, float|int, string}> $expected */
    #[DataProvider('sentences')]
    public function test_measurements_are_found_in_real_product_text(string $text, array $expected): void
    {
        $found = array_map(
            fn (Measurement $m): array => [$m->dimension, Measurement::compact($m->value), $m->unit],
            (new MeasurementScanner)->scan($text),
        );

        $this->assertSame($expected, $found);
    }

    public function test_every_quote_is_an_exact_piece_of_the_text(): void
    {
        $text = "מברגת אימפקט קומפקטית STANLEY FATMAX 10.8V עם 2 סוללות ליתיום 1.5Ah\n• מומנט מירבי 110Nm .\n• משקל 1 ק“ג.";

        foreach ((new MeasurementScanner)->scan($text) as $measurement) {
            $this->assertStringContainsString($measurement->quote, $text);
            $this->assertStringContainsString($measurement->raw, $text);
        }
    }

    public function test_lookalike_characters_match_for_quotes(): void
    {
        $this->assertTrue(TextNormalizer::contains('קוטר פוטר: 13 מ״מ', 'קוטר פוטר: 13 מ"מ'));
        $this->assertTrue(TextNormalizer::contains('משקל: כ‑0.9 ק״ג', 'משקל: כ-0.9 ק"ג'));
        $this->assertTrue(TextNormalizer::contains("מתח\u{200E}: 18V", 'מתח: 18v'));
        $this->assertFalse(TextNormalizer::contains('משקל 2 ק"ג', ''));
    }

    public function test_the_condenser_drops_links_repeats_and_boilerplate_and_keeps_specs_first(): void
    {
        $boilerplate = Boilerplate::find(array_fill(0, 5, ['Makita הנה חברה בינלאומית המתמחה בייצור מקדחות ומברגות נטענות מקצועיות.']));

        $result = (new TextCondenser)->condense([
            'מסור אנכי 4329',
            "מסור אנכי 4329\nMakita הנה חברה בינלאומית המתמחה בייצור מקדחות ומברגות נטענות מקצועיות.\nלמעבר לאתר הספק לחץ כאן\n• הספק: 450W\nמסור נוח ואיכותי לשימוש יומיומי בבית ובסדנה ולעבודות רבות אחרות",
        ], 60, $boilerplate);

        $this->assertSame("מסור אנכי 4329\nהספק: 450W", $result['text']);
        $this->assertTrue($result['truncated']);
    }

    public function test_past_the_budget_a_heading_stays_only_with_what_it_introduces(): void
    {
        $sections = ['טיק ברומזי', "יתרונות המוצר:\nשימושים ויישומים:\nמומלץ להשתמש בברגים איכותיים המתאימים לעץ קשה ולשמור על מרווחי התפשטות\nבמהלך ההתקנה:"];

        $plain = (new TextCondenser)->condense($sections, 70)['text'];
        $this->assertStringContainsString('יתרונות המוצר:', $plain, 'without the option, short headings fill the budget');
        $this->assertStringNotContainsString('ברגים', $plain);

        $text = (new TextCondenser)->condense($sections, 110, headingsWithContent: true)['text'];
        $this->assertSame("טיק ברומזי\nשימושים ויישומים:\nמומלץ להשתמש בברגים איכותיים המתאימים לעץ קשה ולשמור על מרווחי התפשטות", $text);
    }

    public function test_bullets_are_trimmed_without_breaking_hebrew_letters(): void
    {
        // "ע" is D7 A2 in UTF-8, and A2 is also the last byte of "•". trim() would cut it.
        $text = (new TextCondenser)->condense(["• מנוע חזק במיוחד ע\n· תאורה ע"], 500)['text'];

        $this->assertSame("מנוע חזק במיוחד ע\nתאורה ע", $text);
        $this->assertNotFalse(json_encode($text));
    }

    public function test_boilerplate_is_only_long_text_repeated_across_several_products(): void
    {
        $shared = 'מבחר ענק של כלי עבודה חשמליים מבית המותג הבינלאומי להזמנה אונליין';
        $products = [
            ['מקדחה', $shared."\nשנה אחריות"],
            ['מסור', $shared."\nשנה אחריות"],
            ['משחזת', $shared."\nשנה אחריות"],
            ['מלטשת', $shared."\nשנה אחריות"],
        ];

        $this->assertSame([TextNormalizer::forMatching($shared)], Boilerplate::find($products));
        $this->assertSame([], Boilerplate::find(array_slice($products, 0, 3)));
    }
}
