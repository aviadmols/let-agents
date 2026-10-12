<?php

namespace App\Modules\Knowledge\Tests;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Enrichment\Actions\RunNightlyReading;
use App\Modules\Enrichment\Enums\FactKind;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use App\Modules\Knowledge\Actions\TakeKnowledgeSnapshot;
use App\Modules\Knowledge\Models\KnowledgeSnapshot;
use App\Modules\Widget\Actions\BuildPageBank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A site that sells nothing.
 *
 * Everything here was built for a shop, and a shop has products: the readers read them, the
 * relations relate them, the rankings rank them, the snapshot counts them. A magazine has none,
 * and every one of those steps has to finish anyway rather than dividing by zero, writing a
 * nonsense percentage, or reporting a failure for having nothing to do.
 *
 * What a content site does have is articles, and the parts that matter to it — the points in a
 * piece, the questions it can answer, the reading time — have to be there without a single
 * product in the catalogue.
 */
final class ContentOnlySiteTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buildShop();
        // The whole night, relations included.
        Features::override('enrichment.relations', true, $this->shop->id);

        $this->article('900', 'איך בוחרים פרקט לבית', implode("\n", [
            'פרקט עץ מביא חום לחלל, והבחירה בו משנה את אופי החדר לאורך שנים רבות קדימה.',
            '- חשוב לבדוק את עובי השכבה העליונה לפני שקונים',
            '- חשוב לציין שאפשר ללטש פרקט ותיק ולחדש אותו',
            'המדריך הזה למי שמשפץ בית בפעם הראשונה.',
        ]));
        $this->article('901', 'מה ההבדל בין למינציה לפרקט', "למינציה היא חיקוי של עץ, ופרקט הוא עץ אמיתי לכל דבר ועניין.\n- כדאי לזכור שלמינציה עמידה יותר במים");
    }

    public function test_a_night_on_a_site_with_no_products_finishes_every_step(): void
    {
        $run = app(RunNightlyReading::class)->handle($this->shop->id);

        $this->assertSame('succeeded', $run->status->value);
        $this->assertSame([], $run->output['failed'], 'nothing fails for having no products');
        $this->assertCount(count(RunNightlyReading::STEPS), $run->output['steps'], 'and every step still runs');
    }

    public function test_the_articles_are_read_and_carry_what_the_widget_needs(): void
    {
        app(RunNightlyReading::class)->handle($this->shop->id);

        $facts = $this->inShop(fn () => EnrichmentFact::query()
            ->whereNotNull('content_id')
            ->where('status', FactStatus::Approved)
            ->get());

        $points = $facts->where('kind', FactKind::Highlight);
        $this->assertGreaterThanOrEqual(3, $points->count(), 'the points across both guides');

        // The questions the scan decided each page is worth being asked.
        $asks = $facts->where('kind', FactKind::Tag)->filter(fn (EnrichmentFact $f): bool => str_starts_with((string) $f->key, 'ask'));
        $this->assertNotEmpty($asks, 'a reader is offered something to ask');

        // And a reading time, which is the whole of what a magazine wants above a piece.
        $this->assertNotEmpty($facts->where('kind', FactKind::Spec)->where('key', 'reading_minutes'));
    }

    public function test_the_widget_has_something_to_show_on_an_article_and_nothing_it_cannot(): void
    {
        app(RunNightlyReading::class)->handle($this->shop->id);

        $bank = app(BuildPageBank::class)->handle($this->shop->id, 'content', '900', 'he');

        $this->assertTrue($bank['enabled']);
        $candidates = array_column($bank['sections'], 'candidate');

        $this->assertContains('highlights', $candidates, 'the points of the piece');
        $this->assertNotContains('article_products', $candidates, 'and no panel of products it cannot offer');
        $this->assertNotNull($bank['teaser'], 'there is still an opening line');
    }

    public function test_the_snapshot_counts_a_magazine_without_dividing_by_zero(): void
    {
        app(RunNightlyReading::class)->handle($this->shop->id);
        app(TakeKnowledgeSnapshot::class)->handle($this->shop);

        $snapshot = app(TenantContext::class)->runUnscoped(fn () => KnowledgeSnapshot::query()->sole());

        $this->assertSame(0, $snapshot->coverage['catalog']['products']);
        $this->assertSame(2, $snapshot->coverage['catalog']['articles']);
        $this->assertSame(100, $snapshot->coverage['known_share'], 'a magazine is measured on its articles, not on the products it does not sell');
        $this->assertSame('articles', $snapshot->coverage['known_share_of']);
        $this->assertSame(100, $snapshot->coverage['articles_with_points']['share'], 'both guides were read');

        // The gap it should not report: a site with no products is not missing product facts.
        $gaps = collect($snapshot->gaps)->pluck('key');
        $this->assertNotContains('products_without_facts', $gaps);
        $this->assertNotContains('no_relations', $gaps, 'nothing to relate is not a gap');
    }
}
