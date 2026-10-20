<?php

namespace App\Modules\Enrichment\Tests;

use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Scanning\ProductDigest;
use App\Modules\Enrichment\Support\VocabularyBranch;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Enrichment\Vocabulary\VocabularyDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The jewelry vocabulary read against titles and descriptions from a real jewelry store (Rocks and
 * Gold), and the parts of the vocabulary any category can now use: the whole catalog as a root,
 * choices with several values, a product's parts by role, and how sure a picture must be per attribute.
 */
final class JewelryVocabularyTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    private VocabularyDefinition $jewelry;

    protected function setUp(): void
    {
        parent::setUp();

        [$definition, $problems] = VocabularyDefinition::parse((array) ImportVocabulary::template('jewelry'));
        $this->assertSame([], $problems);
        $this->jewelry = $definition;
    }

    public function test_a_jewelry_store_is_one_branch_the_size_of_its_catalog(): void
    {
        $this->buildShop();
        $rings = $this->category('31', 'טבעות');
        $earrings = $this->category('32', 'עגילים');
        $cards = $this->category('39', 'כרטיסי מתנה');
        $this->product('1', 'טבעת וינטג\' משובצת גרנט', '', [$rings]);
        $this->product('2', 'עגילי זהב 14 קארט', '', [$earrings]);
        $this->product('3', 'כרטיס מתנה 500', '', [$cards]);

        $this->assertTrue($this->jewelry->coversWholeCatalog());
        $this->assertSame(['1', '2', '3'], $this->inShop(fn () => VocabularyBranch::products($this->jewelry)->orderBy('external_id')->pluck('external_id')->all()));

        $without = VocabularyDefinition::fromTrusted(['excluded_category_external_ids' => ['39']] + $this->jewelry->toArray());
        $this->assertSame(['1', '2'], $this->inShop(fn () => VocabularyBranch::products($without)->orderBy('external_id')->pluck('external_id')->all()), 'an excluded category stays out of the whole catalog too');
    }

    /** @return array<string, array{string, list<string>}> */
    public static function titles(): array
    {
        return [
            'a ring' => ['טבעת וינטג\' משובצת גרנט - מק"ט 70783, זהב 9K', ['ring']],
            'earrings' => ['עגילי זהב 14 קארט משובצים אבן אדומה -  מקט 11231', ['earrings']],
            'a pendant sold without its chain is not a necklace' => ['תליון בצורת טיפה משובץ אבן אמטיסט ויהלומים, ללא שרשרת - מק"ט 70793, זהב 9K', ['pendant']],
            'a pendant sold with its chain is still a pendant' => ['תליון פרסה משובץ יהלומים, כולל שרשרת משובצת שני יהלומים - מק"ט 52903, זהב 14K', ['pendant']],
            'a necklace' => ['שרשרת זהב לבן עם תליון לב', ['necklace']],
            'a bracelet' => ['צמיד וינטג\' חוליות גורמט ברוחב 3.7 מ"מ - מק"ט 52922, זהב 9K', ['bracelet']],
            'an anklet is not a bracelet' => ['צמיד רגל זהב צהוב', ['anklet']],
        ];
    }

    /** @param list<string> $types */
    #[DataProvider('titles')]
    public function test_the_kind_of_jewel_is_read_from_how_the_title_opens(string $title, array $types): void
    {
        $this->assertSame($types, ProductDigest::typeHints($title, $this->jewelry));
    }

    public function test_the_metal_the_karat_and_the_stone_colour_are_offered_from_the_store_words(): void
    {
        $found = $this->found('עגילי זהב 14 קארט משובצים אבן אדומה. עגילים זהב צהוב 14 קארט, משקל התכשיט 1.6 גרם - עגילים תלוים משובצים אבן אדומה', 'earrings');

        $this->assertContains('metal:yellow_gold', $found);
        $this->assertContains('gold_karat:k14', $found);
        $this->assertContains('stone_color:red', $found);
        $this->assertContains('earring_style:drops', $found);
        $this->assertNotContains('stone_color:yellow', $found, 'yellow gold is the metal, not the stone');
    }

    public function test_rose_gold_is_a_metal_and_a_stone_weight_is_not_a_karat(): void
    {
        $found = $this->found('טבעת וינטג\' מוטיב פרח משובצת רובי במשקל 0.31 קראט ויהלומים, זהב אדום 14K', 'ring');

        $this->assertContains('metal:rose_gold', $found);
        $this->assertNotContains('stone_color:red', $found, '"זהב אדום" is rose gold, not a red stone');
        $this->assertEqualsCanonicalizing(['stone:ruby', 'stone:diamond'], array_values(array_filter($found, fn (string $f): bool => str_starts_with($f, 'stone:'))));
        $this->assertSame(['gold_karat:k14'], array_values(array_filter($found, fn (string $f): bool => str_starts_with($f, 'gold_karat:'))), 'the 0.31 carats of the ruby are not a karat');
        $this->assertContains('motif:flower', $found);
        $this->assertContains('style:vintage', $found);
    }

    public function test_coloured_stones_are_a_colour_not_a_named_stone(): void
    {
        $found = $this->found('טבעת וינטג\' מסדר כוכב המזרח משובצת אבנים צבעוניות ויהלומים - מק"ט 35184, זהב 10K', 'ring');

        $this->assertContains('stone_color:multicolor', $found);
        $this->assertSame(['stone:diamond'], array_values(array_filter($found, fn (string $f): bool => str_starts_with($f, 'stone:'))));
    }

    public function test_a_pendant_without_its_chain_is_not_offered_as_one_with_a_chain(): void
    {
        $this->assertNotContains('includes_chain:1', $this->found('תליון וינטג\' משובץ פנינה ואמטיסט, ללא שרשרת', 'pendant'));
        $this->assertContains('includes_chain:1', $this->found('תליון פרסה משובץ יהלומים, כולל שרשרת', 'pendant'));
    }

    public function test_each_attribute_has_its_own_bar_for_what_a_picture_says(): void
    {
        $this->assertSame(0.92, $this->jewelry->imageFilterConfidence('stone'), 'a stone named wrong is the costliest mistake');
        $this->assertSame(0.7, $this->jewelry->imageFilterConfidence('stone_color'), 'a colour the picture shows filters at once');
        $this->assertSame(VocabularyDefinition::COLOUR_IMAGE_FILTER_CONFIDENCE, VocabularyDefinition::fromTrusted(['attributes' => [['key' => 'color', 'type' => 'enum']]])->imageFilterConfidence('color'), 'any store\x27s colour, without a bar of its own');
        $this->assertSame(0.85, $this->jewelry->imageFilterConfidence(VocabularyDefinition::PRODUCT_TYPE));
        $this->assertSame(0.8, $this->jewelry->imageFilterConfidence('adjustable'), 'the vocabulary\x27s default');

        $bare = VocabularyDefinition::fromTrusted(['key' => 'x']);
        $this->assertSame(VocabularyDefinition::DEFAULT_IMAGE_FILTER_CONFIDENCE, $bare->imageFilterConfidence('stone'), 'a vocabulary that says nothing uses the platform default');

        $this->assertFalse($this->jewelry->readFromImage('gold_karat'));
        $this->assertFalse($this->jewelry->readFromImage('carat_weight'));
        $this->assertTrue($this->jewelry->readFromImage('stone_color'));
    }

    public function test_a_picture_tells_only_how_a_product_looks(): void
    {
        $looks = fn (VocabularyDefinition $v): array => array_values(array_filter(array_column($v->attributes(), 'key'), fn (string $key): bool => $v->readFromImage($key)));

        $this->assertSame(['metal', 'stone', 'stone_color', 'stone_shape', 'setting_style', 'style', 'motif', 'earring_style', 'chain_style'], $looks($this->jewelry),
            'the look of a jewel; never its karat, weight, length, who it is for, or whether a chain comes with it');

        [$tools] = VocabularyDefinition::parse((array) ImportVocabulary::template('power-tools'));
        $this->assertSame([], $looks($tools), 'nothing about a drill from its picture: not its battery, its kit, its power or its motor');

        // A vocabulary written for a clothes branch says nothing about pictures: the key decides, and a vocabulary can say otherwise.
        $clothes = VocabularyDefinition::fromTrusted(['key' => 'tops', 'attributes' => [
            ['key' => 'color', 'type' => 'enum'], ['key' => 'pattern', 'type' => 'enum'], ['key' => 'sleeve_length', 'type' => 'enum'], ['key' => 'fabric', 'type' => 'enum'],
            ['key' => 'size', 'type' => 'enum'], ['key' => 'washable', 'type' => 'boolean'], ['key' => 'cotton_percent', 'type' => 'number'],
            ['key' => 'occasion', 'type' => 'enum', 'visible' => true], ['key' => 'main_color', 'type' => 'enum', 'visible' => false],
        ]]);
        $this->assertSame(['color', 'pattern', 'sleeve_length', 'fabric', 'occasion'], $looks($clothes));
    }

    public function test_the_parts_of_a_jewel_name_where_a_stone_sits(): void
    {
        $center = collect($this->jewelry->components())->firstWhere('key', 'center');

        $this->assertSame(['stone', 'stone_color', 'stone_shape', 'carat_weight'], $center['attributes']);
        $this->assertMatchesRegularExpression('~'.$center['patterns'][2].'~u', 'טבעת זהב עם אבן אדומה באמצע');
        $this->assertTrue($this->jewelry->isMultiple('stone'));
        $this->assertFalse($this->jewelry->isMultiple('gold_karat'));
    }

    public function test_the_new_parts_of_a_vocabulary_are_checked_like_the_rest(): void
    {
        $data = $this->jewelry->toArray();
        $data['components'][0]['attributes'][] = 'band_shape';
        $data['image']['filter_confidence']['stone'] = 1.4;
        $data['image']['filter_confidence']['sparkle'] = 0.5;
        $data['image']['never'][] = 'price';
        $data['attributes'][15]['multiple'] = true;

        [$definition, $problems] = VocabularyDefinition::parse($data);

        $this->assertNull($definition);
        $this->assertEqualsCanonicalizing([
            'components[0].attributes: unknown attribute band_shape',
            'image.filter_confidence.stone must be between 0 and 1',
            'image.filter_confidence: unknown attribute sparkle',
            'image.never: unknown attribute price',
            'attributes[15].multiple is true or false, on a choice only',
        ], $problems);
    }

    /** @return list<string> "attribute:value" for every candidate code offers */
    private function found(string $text, string $type): array
    {
        return array_map(fn (array $c): string => $c['key'].':'.(is_bool($c['value']) ? (int) $c['value'] : $c['value']), ProductDigest::candidates($text, $this->jewelry, $type));
    }
}
