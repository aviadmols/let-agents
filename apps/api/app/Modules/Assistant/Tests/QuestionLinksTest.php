<?php

namespace App\Modules\Assistant\Tests;

use App\Modules\Admin\Models\User;
use App\Modules\Assistant\Filament\Merchant\Pages\ShopQuestions;
use App\Modules\Assistant\Models\AssistantQuestionLink;
use App\Modules\Assistant\Models\AssistantSearchAsk;
use App\Modules\Assistant\Support\Question;
use App\Modules\Assistant\Support\QuestionLinks;
use App\Modules\Assistant\Support\RelatedGroups;
use App\Modules\Catalog\Models\CatalogCategory;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A broad question ("תראה לי טבעות נישואין") is answered from what the store means (Rocks, 28.10): the categories and
 * tags its team linked the question to, which take in a product added tomorrow, else those whose products say the
 * question, found by code in their descriptions, though no category is named for weddings.
 */
final class QuestionLinksTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    /** @var array<string, CatalogCategory> */
    private array $groups = [];

    public function test_the_categories_whose_products_say_the_question_are_its_own(): void
    {
        $this->rings();

        $found = $this->inShop(fn (): array => RelatedGroups::for($this->shop->id, 'תראה לי טבעות נישואין'));

        $names = array_column($found['groups'], 'name');
        $this->assertContains('טבעת סוליטר', $names);
        $this->assertContains('טבעת עיגול חלקה', $names);
        $this->assertContains('חתונה', $names, 'a tag of the store\'s too');
        $this->assertNotContains('טבעות אירוסין', $names, 'rings no description says are for a wedding');
        $this->assertNotContains('שרשראות', $names, 'a wedding gift that is no ring');
        $this->assertNotContains('תכשיטים', $names, 'the whole store says nothing of the question');
        $this->assertEqualsCanonicalizing(['r1', 'r2', 'r4', 'r5', 'r6'], $found['ids']);

        $this->assertSame(['groups' => [], 'ids' => []], $this->inShop(fn (): array => RelatedGroups::for($this->shop->id, 'יש לכם שעוני קיר?')), 'words no product says: nothing');
    }

    public function test_a_linked_question_takes_in_what_its_categories_and_tags_hold_today(): void
    {
        $this->rings();
        $link = $this->inShop(fn () => AssistantQuestionLink::query()->create([
            'shop_id' => $this->shop->id, 'question_key' => Question::key('טבעות נישואין'), 'question' => 'טבעות נישואין',
            'categories' => [$this->groups['טבעות אירוסין']->external_id], 'tags' => ['CLASSIC'], 'products' => [],
        ]));

        $this->assertSame($link->id, $this->inShop(fn () => QuestionLinks::for('טבעות נישואין?'))?->id, 'asked the same way');
        $this->assertSame($link->id, $this->inShop(fn () => QuestionLinks::for('טבעות נישואין יפות'))?->id, 'worded like it');
        $this->assertNull($this->inShop(fn () => QuestionLinks::for('שרשרת זהב')));

        $this->assertEqualsCanonicalizing(['r7', 'r8', 'r9', 'r3'], $this->inShop(fn (): array => QuestionLinks::products($link)), 'its category, and its tag in any case');

        // a ring the store adds after the link was made is offered by it, untagged by anyone
        $this->product('r10', 'טבעת אירוסין חדשה', 'טבעת חדשה', [$this->groups['טבעות אירוסין'], $this->groups['תכשיטים']]);
        $this->assertContains('r10', $this->inShop(fn (): array => QuestionLinks::products($link)));
    }

    public function test_the_team_links_a_question_to_categories_and_tags_from_the_questions_screen(): void
    {
        $this->rings();
        $this->inShop(fn () => AssistantSearchAsk::query()->create([
            'shop_id' => $this->shop->id, 'day' => now()->toDateString(), 'question' => 'טבעות נישואין', 'products' => [], 'outcome' => 'answered', 'from' => 'model',
        ]));
        $row = 's:'.Question::key('טבעות נישואין');

        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop);
        $this->actingAs($merchant);
        Filament::setCurrentPanel('merchant');
        Filament::setTenant($this->shop);

        Livewire::test(ShopQuestions::class)
            ->call('startLink', $row)
            ->call('toggleLink', 'categories', $this->groups['טבעת סוליטר']->external_id)
            ->call('toggleLink', 'tags', 'חתונה')
            ->call('saveLink');

        $link = $this->inShop(fn () => AssistantQuestionLink::query()->sole());
        $this->assertSame([[$this->groups['טבעת סוליטר']->external_id], ['חתונה']], [$link->categories, $link->tags]);
    }

    private function rings(): void
    {
        $this->buildShop();
        foreach (['תכשיטים', 'טבעת סוליטר', 'טבעת עיגול חלקה', 'טבעות אירוסין', 'שרשראות'] as $i => $name) {
            $this->groups[$name] = $this->category((string) (100 + $i), $name);
        }
        $all = $this->groups['תכשיטים'];
        $ring = fn (string $id, string $title, string $text, string $group, array $tags = [], string $price = '1500.00') => $this->product($id, $title, $text, [$this->groups[$group], $all], [
            'price' => $price,
            'payload' => ['title' => $title, 'short_description' => '', 'description' => '<p>'.$text.'</p>', 'tags' => $tags, 'attributes' => [], 'meta' => []],
        ]);
        $ring('r1', 'טבעת סוליטר יהלום', 'טבעת קלאסית, בחירה מושלמת לטבעת נישואין.', 'טבעת סוליטר', ['חתונה'], '3200.00');
        $ring('r2', 'טבעת סוליטר זהב לבן', 'סוליטר עדין ליום הנישואין.', 'טבעת סוליטר', ['חתונה']);
        $ring('r3', 'טבעת סוליטר קטנה', 'סוליטר לכל יום.', 'טבעת סוליטר', ['Classic']);
        $ring('r4', 'טבעת עיגול חלקה זהב', 'טבעת נישואין חלקה מזהב 14 קראט.', 'טבעת עיגול חלקה', [], '900.00');
        $ring('r5', 'טבעת עיגול חלקה רחבה', 'טבעת עיגול רחבה, אהובה כטבעת נישואין.', 'טבעת עיגול חלקה');
        $ring('r6', 'טבעת עיגול חלקה דקה', 'טבעת דקה לנישואין.', 'טבעת עיגול חלקה', [], '700.00');
        $ring('r7', 'טבעת אירוסין טיפה', 'טבעת אירוסין עם אבן בצורת טיפה.', 'טבעות אירוסין');
        $ring('r8', 'טבעת אירוסין פרח', 'טבעת אירוסין בעיצוב פרח.', 'טבעות אירוסין');
        $ring('r9', 'טבעת אירוסין הילה', 'טבעת אירוסין עם הילת יהלומים.', 'טבעות אירוסין');
        $ring('n1', 'שרשרת לב', 'מתנה מושלמת ליום הנישואין.', 'שרשראות');
        $ring('n2', 'שרשרת טיפה', 'שרשרת עדינה.', 'שרשראות');
        $ring('n3', 'שרשרת אבן', 'שרשרת עם אבן.', 'שרשראות');
    }
}
