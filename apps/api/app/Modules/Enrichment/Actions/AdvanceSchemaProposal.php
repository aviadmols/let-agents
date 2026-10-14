<?php

namespace App\Modules\Enrichment\Actions;

use App\Core\Facades\Features;
use App\Core\Facades\Settings;
use App\Modules\Enrichment\Models\EnrichmentSchemaProposal;

/**
 * The gate before the full scan (docs/strict-scan.md): a scored vocabulary goes on by itself only
 * when the shop has turned that on, the score reaches the pass score, and no hard failure stands.
 * Otherwise it waits for a person, with the reason. A person's decision (DecideSchemaProposal) is
 * always open. No model is called here.
 */
final class AdvanceSchemaProposal
{
    public const APPROVED = 'approved';

    public const HELD = 'held';

    public function __construct(private readonly DecideSchemaProposal $decide) {}

    /** @return array{outcome: string, why: string} why is a key: auto_off, no_score, hard_failure, below_pass or passed */
    public function handle(EnrichmentSchemaProposal $proposal): array
    {
        if ($proposal->status !== EnrichmentSchemaProposal::PROPOSED || $proposal->score === null) {
            return ['outcome' => self::HELD, 'why' => 'no_score'];
        }

        if (! empty($proposal->hard_failures)) {
            return ['outcome' => self::HELD, 'why' => 'hard_failure'];
        }

        if ($proposal->score < (int) Settings::get('enrichment.schema_pass_score', $proposal->shop_id)) {
            return ['outcome' => self::HELD, 'why' => 'below_pass'];
        }

        if (! Features::enabled('enrichment.schema_auto_advance', $proposal->shop_id)) {
            return ['outcome' => self::HELD, 'why' => 'auto_off'];
        }

        $this->decide->handle($proposal, true);

        return ['outcome' => $proposal->refresh()->status === EnrichmentSchemaProposal::APPROVED ? self::APPROVED : self::HELD, 'why' => 'passed'];
    }
}
