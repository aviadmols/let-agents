<?php

namespace App\Modules\Admin\Contracts;

use Carbon\CarbonInterface;

/**
 * A module's daily reports, as cards: the questions shoppers asked yesterday and what came of them,
 * the site's daily check and what it proposes. A module tags its class 'admin.daily_summaries'; the
 * shop's home shows each kind as a slider of days, and the operator's page all of them by day.
 */
interface DailySummaries
{
    public const TAG = 'admin.daily_summaries';

    /** What these reports are, in the shop's words ("The questions shoppers asked"). */
    public function label(): string;

    /**
     * The reports since a day, the latest first: of one shop (in its tenant), or of every shop when
     * $shopId is null (the operator's, across shops). $operator: the reports may say how, not only what.
     *
     * @return list<DailySummary>
     */
    public function summaries(?string $shopId, CarbonInterface $since, bool $operator = false): array;
}
