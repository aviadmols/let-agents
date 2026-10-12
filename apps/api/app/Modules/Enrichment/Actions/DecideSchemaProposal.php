<?php

namespace App\Modules\Enrichment\Actions;

use App\Modules\Enrichment\Models\EnrichmentSchemaProposal;
use Illuminate\Support\Facades\Auth;

/**
 * The operator's decision on a proposed vocabulary. Approved, it becomes the shop's next version
 * of that vocabulary, exactly as an imported one (see ImportVocabulary), and code reads the
 * catalog with it on its next run; rejected, it stays only as a record.
 */
final class DecideSchemaProposal
{
    public function __construct(private readonly ImportVocabulary $import) {}

    /** @return string|null the summary of what happened, or null when the proposal cannot be decided */
    public function handle(EnrichmentSchemaProposal $proposal, bool $approve): ?string
    {
        if ($proposal->status !== EnrichmentSchemaProposal::PROPOSED || $proposal->proposed === null) {
            return null;
        }

        if (! $approve) {
            $proposal->update(['status' => EnrichmentSchemaProposal::REJECTED, 'decided_by' => Auth::id(), 'decided_at' => now()]);

            return (string) __('enrichment::ui.schema.rejected');
        }

        ['run' => $run, 'vocabulary' => $vocabulary] = $this->import->handle($proposal->shop_id, $proposal->proposed, 'schema-discovery');

        if ($vocabulary === null) {
            return $run->summary();
        }

        $proposal->update([
            'status' => EnrichmentSchemaProposal::APPROVED,
            'vocabulary_id' => $vocabulary->id,
            'decided_by' => Auth::id(),
            'decided_at' => now(),
        ]);

        return $run->summary();
    }
}
