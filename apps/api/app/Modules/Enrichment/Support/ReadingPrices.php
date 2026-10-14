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
    public static function role(TaskType $task): string
    {
        return $task === TaskType::FactReview ? 'reviewer' : 'reader';
    }

    public static function input(TaskType $task): float
    {
        return (float) Settings::get('enrichment.'.self::role($task).'_input_usd_per_million');
    }

    public static function output(TaskType $task): float
    {
        return (float) Settings::get('enrichment.'.self::role($task).'_output_usd_per_million');
    }
}
