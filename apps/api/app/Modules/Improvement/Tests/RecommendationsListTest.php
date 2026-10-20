<?php

namespace App\Modules\Improvement\Tests;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Improvement\Filament\Operator\Pages\SiteSuggestions;
use App\Modules\Improvement\Models\ImprovementProposal;
use App\Modules\Improvement\Models\ImprovementReview;
use App\Modules\Search\Models\SearchTerm;
use App\Modules\Tenancy\Models\Shop;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The recommendations as a list and the panel each opens in: its priority among the others by the
 * searches and questions behind it, what it rests on search by search, and, once decided, what
 * happened to those searches since against the days before.
 */
final class RecommendationsListTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = Shop::factory()->create(['name' => 'סלע מאיר']);
        app()->setLocale('he');
        Filament::setCurrentPanel(Filament::getPanel('operator'));
        $this->actingAs(User::factory()->operator()->create());
    }

    public function test_each_recommendation_shows_what_it_rests_on_its_priority_and_its_impact(): void
    {
        $this->travelTo(now()->startOfDay()->setHour(12));
        $busy = $this->proposal('content_gap', 'ספרים על החטופים', ['topic' => 'ספרים על החטופים', 'why' => 'מחפשים ואין.'], [['ref' => 's1', 'query' => 'ספרי חטופים', 'searches' => 40]]);
        $this->proposal('faq', 'כמה זמן לוקח משלוח?', ['question' => 'כמה זמן לוקח משלוח?', 'answer' => ''], [['ref' => 'q1', 'question' => 'כמה זמן לוקח משלוח?', 'asked' => 6]]);
        $applied = $this->proposal('synonym', 'ספרי מופת = קלאסיקה', ['term' => 'ספרי מופת', 'means' => 'קלאסיקה'], [['ref' => 'u1', 'query' => 'ספרי מופת', 'searches' => 3, 'results' => 12]],
            ['status' => ImprovementProposal::APPLIED, 'decided_at' => now()->subDays(3)]);

        app(TenantContext::class)->run($this->shop->id, function (): void {
            foreach ([[5, 4, 4, 0], [4, 3, 3, 0], [2, 6, 1, 3], [1, 5, 0, 4]] as [$ago, $searches, $empty, $clicks]) {
                SearchTerm::query()->create(['shop_id' => $this->shop->id, 'day' => now()->subDays($ago)->toDateString(), 'query' => 'ספרי מופת', 'searches' => $searches, 'empty' => $empty, 'clicks' => $clicks]);
            }
        });

        $page = Livewire::test(SiteSuggestions::class, ['shop' => $this->shop->id]);
        $rows = collect($page->instance()->rows($page->instance()->suggestions()))->keyBy(fn (array $r): string => $r['p']->id);

        $this->assertSame('high', $rows[$busy->id]['priority'], 'the most searched');
        $this->assertSame('low', $rows[$applied->id]['priority']);
        $this->assertSame(['days' => 3, 'before' => ['searches' => 7, 'empty' => 7, 'clicks' => 0], 'after' => ['searches' => 11, 'empty' => 1, 'clicks' => 7]], $rows[$applied->id]['impact'],
            'the 3 days since the decision against the 3 before it');

        $page->assertSee('כל ההמלצות')
            ->assertSee('על מה ההמלצה מבוססת')
            ->assertSee('ספרי חטופים')
            ->assertSee('לא נמצא כלום')
            ->assertSee('נשאל בעוזר')
            ->assertSee('נמצאו 12 תוצאות ואף אחת לא נלחצה')
            ->assertSee('חיפוש של «ספרי מופת» ימצא גם את מה שהחנות קוראת לו «קלאסיקה».')
            ->assertSee('לפני: 7');
    }

    /**
     * @param  list<array<string, mixed>>  $evidence
     * @param  array<string, mixed>  $extra
     */
    private function proposal(string $kind, string $title, array $detail, array $evidence, array $extra = []): ImprovementProposal
    {
        return app(TenantContext::class)->run($this->shop->id, function () use ($kind, $title, $detail, $evidence, $extra): ImprovementProposal {
            $review = ImprovementReview::query()->first() ?? ImprovementReview::query()->create(['shop_id' => $this->shop->id, 'day' => now()->toDateString(), 'status' => 'reviewed', 'evidence' => [], 'digest' => '']);

            return ImprovementProposal::query()->create($extra + [
                'shop_id' => $this->shop->id, 'review_id' => $review->id, 'kind' => $kind, 'title' => $title, 'detail' => $detail, 'evidence' => $evidence,
                'fingerprint' => hash('sha256', $kind.$title), 'status' => ImprovementProposal::PENDING, 'proposed_by' => 'openai gpt-5.4-mini',
            ]);
        });
    }
}
