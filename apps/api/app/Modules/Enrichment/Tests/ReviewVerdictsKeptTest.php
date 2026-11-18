<?php

namespace App\Modules\Enrichment\Tests;

use App\Core\Facades\Features;
use App\Modules\Enrichment\Actions\ImportVocabulary;
use App\Modules\Enrichment\Actions\ReadProductsInCode;
use App\Modules\Enrichment\Enums\FactStatus;
use App\Modules\Enrichment\Enums\Verdict;
use App\Modules\Enrichment\Models\EnrichmentFact;
use App\Modules\Enrichment\Tests\Concerns\BuildsCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aviad, 18.11: no paying twice for thousands of tags already checked. A re-read (a new version of the bank, a changed
 * product) wrote every code fact anew, and every reviewer's verdict on them was lost: Rocks' 848 rejections were 174 after
 * one. The same claim read again (its kind, key, value and source) keeps the verdict it was given.
 */
final class ReviewVerdictsKeptTest extends TestCase
{
    use BuildsCatalog;
    use RefreshDatabase;

    public function test_a_claim_read_again_keeps_its_reviewers_verdict_and_only_a_new_one_waits(): void
    {
        $this->buildShop();
        Features::override('enrichment.read_text_in_code', true, $this->shop->id);
        $jewelry = (array) ImportVocabulary::template('jewelry');
        app(ImportVocabulary::class)->handle($this->shop->id, $jewelry, 'test');
        $ring = $this->product('1', 'טבעת זהב משובצת אבני רובי ויהלומים', '', [$this->category('31', 'טבעות')]);
        app(ReadProductsInCode::class)->handle($this->shop->id);

        $stone = fn (string $value): ?EnrichmentFact => $this->inShop(fn () => EnrichmentFact::query()->where('product_id', $ring->id)->where('key', 'stone')
            ->where('value_text', $value)->where('status', '!=', FactStatus::Superseded)->first());
        $this->assertNotNull($stone('ruby'));
        // the reviewer found the ruby wrong, and the diamond right
        $this->inShop(function () use ($stone): void {
            $stone('ruby')->forceFill(['status' => FactStatus::Rejected, 'status_reason' => 'review_wrong', 'review_verdict' => Verdict::Wrong, 'review_model' => 'claude-haiku-4-5', 'review_tier' => 1])->save();
            $stone('diamond')->forceFill(['review_verdict' => Verdict::Ok, 'review_model' => 'claude-haiku-4-5', 'review_tier' => 1])->save();
        });

        // a new version of the bank (here, one more word for a value) reads every product again
        $next = $jewelry;
        $next['name'] = ['he' => 'תכשיטים', 'en' => 'Jewellery, completed'];
        $imported = app(ImportVocabulary::class)->handle($this->shop->id, $next, 'test again');
        $this->assertSame(2, $imported['vocabulary']?->version, 'a new version');
        $run = app(ReadProductsInCode::class)->handle($this->shop->id);
        $this->assertSame(1, $run->output['changed'] ?? null, 'the ring read again');

        $this->assertSame(FactStatus::Rejected, $stone('ruby')?->status, 'still rejected: never sent to the reviewer again');
        $this->assertSame(Verdict::Wrong, $stone('ruby')?->review_verdict);
        $this->assertSame(FactStatus::Approved, $stone('diamond')?->status);
        $this->assertSame(Verdict::Ok, $stone('diamond')?->review_verdict, 'and the right one keeps that it was checked');
        $this->assertGreaterThanOrEqual(2, $run->output['verdicts_kept'] ?? 0);
    }
}
