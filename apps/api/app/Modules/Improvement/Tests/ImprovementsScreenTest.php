<?php

namespace App\Modules\Improvement\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Filament\Merchant\Pages\Improvements;
use App\Modules\Admin\Models\User;
use App\Modules\Assistant\Models\AssistantAnswer;
use App\Modules\Improvement\Models\ImprovementProposal;
use App\Modules\Search\Models\SearchFamilyChoice;
use App\Modules\Search\Models\SearchSynonym;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Improvements screen (the redesign, 11.2026): every fix waiting in one list, each with its suggestion; a drafted
 * answer edited and published in one tap; a word that found nothing approved in one tap; "Not relevant" for good; what
 * Lets fixed on its own, with undo; what was approved.
 */
final class ImprovementsScreenTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create(['name' => 'גואטה']);
        $merchant = User::factory()->create();
        $merchant->attachShop($this->shop);
        $this->actingAs($merchant);
        Filament::setCurrentPanel('merchant');
        Filament::setTenant($this->shop);

        $this->inShop(function (): void {
            $make = fn (string $kind, string $title, array $detail, array $evidence) => ImprovementProposal::query()->create([
                'shop_id' => $this->shop->id, 'kind' => $kind, 'title' => $title, 'detail' => $detail, 'evidence' => $evidence,
                'fingerprint' => hash('sha256', $kind.$title), 'status' => ImprovementProposal::PENDING, 'proposed_by' => 'x',
            ]);
            $make(ImprovementProposal::SYNONYM, 'sandles', ['term' => 'sandles', 'means' => 'sandals'], [['query' => 'sandles', 'searches' => 21], ['query' => 'sandls', 'searches' => 9]]);
            $make(ImprovementProposal::FAQ, 'בטנה', ['question' => 'יש לשמלה בטנה?', 'answer' => 'כן, יש לה בטנה מכותנה.'], [['question' => 'יש לשמלה בטנה?', 'asked' => 23]]);
            $make(ImprovementProposal::CONTENT_GAP, 'מתנות', ['topic' => 'מדריך מתנות לאמא', 'why' => 'חיפשו מתנה לאמא ואין עמוד כזה.'], [['query' => 'מתנה לאמא', 'searches' => 41]]);
            SearchFamilyChoice::query()->create(['shop_id' => $this->shop->id, 'status' => SearchFamilyChoice::APPROVED, 'words' => ['סניקרס', 'סניקרז'], 'auto' => true]);
        });
    }

    public function test_every_fix_waits_with_its_suggestion_and_what_lets_fixed_can_be_undone(): void
    {
        $page = Livewire::test(Improvements::class)
            ->assertSee('data-improvements', false)
            ->assertSee('sandals')
            ->assertSee('כן, יש לה בטנה מכותנה.')
            ->assertSee('מדריך מתנות לאמא')
            ->assertSee('מה בדיוק הקלידו')
            ->assertSee('sandls');

        // the kinds there are, and only those
        $this->assertSame(['nothing' => 1, 'question' => 1, 'idea' => 1], $page->instance()->kinds());
        $page->call('filter', 'question')->assertSee('כן, יש לה בטנה מכותנה.')->assertDontSee('מדריך מתנות לאמא');

        // the drafted answer, edited, published as edited
        $faq = $this->inShop(fn () => ImprovementProposal::query()->where('kind', ImprovementProposal::FAQ)->value('id'));
        $page->set('drafts.'.Improvements::draftKey('proposal:'.$faq), 'כן, בטנה קלה מכותנה מהמותניים ומטה.')
            ->call('approve', 'proposal:'.$faq)->assertNotified();
        $this->assertSame('כן, בטנה קלה מכותנה מהמותניים ומטה.', $this->inShop(fn () => AssistantAnswer::query()->where('source', 'team')->value('answer')));

        // one tap: the word that found nothing now means what shoppers meant
        $synonym = $this->inShop(fn () => ImprovementProposal::query()->where('kind', ImprovementProposal::SYNONYM)->value('id'));
        $page->call('approve', 'proposal:'.$synonym);
        $this->assertTrue($this->inShop(fn () => SearchSynonym::query()->where('term', 'sandles')->where('means', 'sandals')->exists()));

        // not relevant: rejected, never suggested again
        $gap = $this->inShop(fn () => ImprovementProposal::query()->where('kind', ImprovementProposal::CONTENT_GAP)->value('id'));
        $page->call('later', 'proposal:'.$gap);
        $this->assertSame([], $page->instance()->fixes(), 'nothing waits now');

        // fixed by Lets, and undone
        $page->call('show', 'auto')->assertSee('סניקרס')->assertSee('לבטל');
        $family = $this->inShop(fn () => SearchFamilyChoice::query()->value('id'));
        $page->call('undo', 'family:'.$family)->assertNotified();
        $this->assertSame(SearchFamilyChoice::REJECTED, $this->inShop(fn () => SearchFamilyChoice::query()->latest('updated_at')->value('status')));
    }

    public function test_not_relevant_rejects_a_proposal_for_good(): void
    {
        $synonym = $this->inShop(fn () => ImprovementProposal::query()->where('kind', ImprovementProposal::SYNONYM)->value('id'));
        Livewire::test(Improvements::class)->call('dismiss', 'proposal:'.$synonym)->assertNotified();
        $this->assertSame(ImprovementProposal::REJECTED, $this->inShop(fn () => ImprovementProposal::query()->find($synonym)->status));
    }

    /**
     * @template T
     *
     * @param  callable(): T  $run
     * @return T
     */
    private function inShop(callable $run): mixed
    {
        return app(TenantContext::class)->run((string) $this->shop->id, $run);
    }
}
