<?php

namespace App\Modules\Improvement\Actions;

use App\Modules\Improvement\Models\ImprovementProposal;
use App\Modules\Search\Models\SearchSynonym;

/**
 * The team's decision on a proposal. Approving a synonym puts it into the search (from the next
 * update); approving a question or a missing topic marks it as work the team took on. Rejecting
 * keeps it, so the review never proposes it again. Callers are inside the shop's tenant.
 */
final class DecideProposal
{
    public function approve(ImprovementProposal $proposal, ?int $userId): void
    {
        if (! in_array($proposal->status, [ImprovementProposal::PENDING, ImprovementProposal::REFUSED], true)) {
            return;
        }

        if ($proposal->kind === ImprovementProposal::SYNONYM) {
            SearchSynonym::query()->firstOrCreate(
                ['term' => (string) $proposal->detail['term'], 'means' => (string) $proposal->detail['means']],
                ['shop_id' => $proposal->shop_id, 'origin' => 'review'],
            );
        }

        $proposal->update([
            'status' => $proposal->kind === ImprovementProposal::SYNONYM ? ImprovementProposal::APPLIED : ImprovementProposal::APPROVED,
            'decided_by' => $userId,
            'decided_at' => now(),
        ]);
    }

    public function reject(ImprovementProposal $proposal, ?int $userId): void
    {
        if ($proposal->status === ImprovementProposal::APPLIED && $proposal->kind === ImprovementProposal::SYNONYM) {
            SearchSynonym::query()->where('term', $proposal->detail['term'])->where('means', $proposal->detail['means'])->where('origin', 'review')->delete();
        }

        $proposal->update(['status' => ImprovementProposal::REJECTED, 'decided_by' => $userId, 'decided_at' => now()]);
    }
}
