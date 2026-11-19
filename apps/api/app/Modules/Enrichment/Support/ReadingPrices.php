<?php

namespace App\Modules\Enrichment\Support;

use App\Core\Facades\Settings;
use App\Modules\Enrichment\Enums\TaskType;

/**
 * The price per million tokens of the model that answers a kind of question: a review of facts is
 * the reviewer's (the other family), everything else the reader's.
 */
final class ReadingPrices
{
    /** A review of what the first reviewer was unsure of (tier 2) is the stronger model's, at its own price. */
    public static function role(TaskType $task, int $tier = 1): string
    {
        return match (true) {
            $task === TaskType::FactReview => $tier >= 2 ? 'escalation' : 'reviewer',
            // the type audit is the auditor's own (AuditProductTypes), of another family than the bank's writer
            $task === TaskType::TypeAudit => 'type_auditor',
            default => 'reader',
        };
    }

    public static function input(TaskType $task, int $tier = 1): float
    {
        return (float) Settings::get('enrichment.'.self::role($task, $tier).'_input_usd_per_million');
    }

    public static function output(TaskType $task, int $tier = 1): float
    {
        return (float) Settings::get('enrichment.'.self::role($task, $tier).'_output_usd_per_million');
    }
}
