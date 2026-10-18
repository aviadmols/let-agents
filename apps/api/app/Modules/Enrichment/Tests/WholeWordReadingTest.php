<?php

namespace App\Modules\Enrichment\Tests;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Scanning\BrandResolver;
use App\Modules\Enrichment\Scanning\CodeReading;
use App\Modules\Enrichment\Scanning\PatternMatch;
use App\Modules\Enrichment\Scanning\WordAudit;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A shop's word names a thing only as a word of its own ("ספיר" is no sapphire inside "ספירלה"),
 * and a category that names several of an attribute's values groups products ("ספיר, רובי,
 * אמרלד") rather than saying which one this is. As Rocks and Gold's spiral rings and its
 * three-stone collection taught.
 */
final class WholeWordReadingTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    /** @return array<string, array{string, string, bool}> */
    public static function words(): array
    {
        return [
            'the word itself' => ['טבעת ספיר כחול', 'ספיר', true],
            'with a prefix letter' => ['טבעת משובצת בספיר', 'ספיר', true],
            'in its plural' => ['עגילים משובצים ספירים', 'ספיר', true],
            'inside a spiral' => ['טבעת וינטג\' מוטיב ספירלה', 'ספיר', false],
            'inside a longer word, before it' => ['טבעת אספירין', 'ספיר', false],
            'a stem with its feminine ending' => ['טבעת משובצת יהלומים', 'משובצ', true],
            'an English plural' => ['Sapphires ring', 'sapphire', true],
            'English inside a word' => ['rubylite pendant', 'ruby', false],
            'next to a number' => ['טבעת זהב 14K', '14k', true],
        ];
    }

    #[DataProvider('words')]
    public function test_a_pattern_names_a_whole_word_only(string $text, string $pattern, bool $named): void
    {
        $this->assertSame($named, PatternMatch::any($text, [$pattern]));
    }

    public function test_a_spiral_is_no_sapphire_and_a_collection_of_stones_names_none_of_them(): void
    {
        $this->buildShop();
        $data = (array) ImportVocabulary::template('jewelry');
        // As Rocks's own vocabulary wrote it: the bare word, with no guard of its own.
        foreach ($data['attributes'] as &$attribute) {
            if ($attribute['key'] === 'stone') {
                foreach ($attribute['values'] as &$value) {
                    $value['patterns'] = match ($value['key']) {
                        'sapphire' => ['ספיר'], 'ruby' => ['רובי'], 'emerald' => ['אמרלד'], default => $value['patterns'],
                    };
                }
            }
        }
        unset($attribute, $value);
        [$jewelry, $problems] = VocabularyDefinition::parse($data);
        $this->assertSame([], $problems);

        $collection = $this->category('77', 'ספיר, רובי, אמרלד');
        $rings = $this->category('31', 'טבעות');
        $spiral = $this->product('1', 'טבעת וינטג\' מוטיב ספירלה משובצת יהלומים - מק"ט 52848, זהב 14K', '', [$rings]);
        $ruby = $this->product('2', 'טבעת וינטאג משובצת אבני רובי ויהלומים', '', [$rings, $collection]);
        $listed = $this->product('3', 'טבעת וינטג\' משובצת אבנים', '', [$collection], ['payload' => ['attributes' => [['name' => 'אבן', 'values' => ['ספיר', 'יהלום']]]]]);

        $stones = fn ($product): array => $this->inShop(fn () => CodeReading::read($product->fresh(), new BrandResolver, $jewelry, true)['text_choices']['stone']['values'] ?? []);

        $this->assertNotContains('sapphire', $stones($spiral), 'ספירלה is a spiral');
        $this->assertEqualsCanonicalizing(['diamond', 'ruby'], $stones($ruby), 'the collection names three stones; the title names the ruby and the diamonds, no sapphire, no emerald');
        $this->assertContains('sapphire', $stones($listed), 'a store attribute listing its stones describes this one');

        // The operator's check finds both, across the catalog, before code is misled.
        $audit = $this->inShop(fn () => WordAudit::of($jewelry, CatalogProduct::query()->with('categories')->get()));
        $spiralRow = collect($audit['inside'])->firstWhere('word', 'ספירלה');
        $this->assertSame(['stone', 'sapphire', 1], [$spiralRow['attribute'], $spiralRow['value'], $spiralRow['products']]);
        $group = collect($audit['groups'])->firstWhere('name', 'ספיר, רובי, אמרלד');
        // The ruby ring is filed in it (the third product's own payload carries no categories).
        $this->assertSame(['stone', 1], [$group['attribute'], $group['products']]);
        $this->assertEqualsCanonicalizing(['sapphire', 'ruby', 'emerald'], $group['values']);
    }

    public function test_the_stores_own_colour_attribute_is_the_whole_list_of_colours(): void
    {
        // As Gueta's MDF board taught (2026-10-18): sold in green, its description says "and black".
        $this->buildShop();
        [$adhesives] = VocabularyDefinition::parse((array) ImportVocabulary::template('adhesives'));
        $colours = fn ($product): array => $this->inShop(fn () => CodeReading::read($product->fresh(), new BrandResolver, $adhesives, true)['text_choices']['color']['values'] ?? []);
        $said = 'מגיע בצבעים: ירוק ושחור';
        $with = fn (string $name, array $values): array => ['payload' => ['title' => 'פלטת MDF', 'description' => $said, 'attributes' => [['name' => $name, 'values' => $values]]]];

        $board = $this->product('1', 'פלטת MDF', $said, [], $with('צבע', ['ירוק']));
        $shade = $this->product('2', 'פלטת MDF', $said, [], $with('גוון', ['ירוק', 'שחור מט']));
        $plain = $this->product('3', 'פלטת MDF', $said, []);
        $other = $this->product('4', 'פלטת MDF', $said, [], $with('עובי', ['8 מ"מ']));

        $this->assertSame([], $colours($board), 'black is not among the colours it is sold in');
        $this->assertSame(['black'], $colours($shade), 'its own list names black');
        $this->assertSame(['black'], $colours($plain), 'no list of its own: the description is read as before');
        $this->assertSame(['black'], $colours($other), 'an attribute that is not a colour is no list of colours');
    }
}
