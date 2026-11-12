<?php

namespace App\Modules\Admin\Actions;

use App\Core\Facades\Settings;
use App\Modules\Admin\Filament\Merchant\Pages\ReportSettings;
use App\Modules\Admin\Mail\StoreReportMail;
use App\Modules\Admin\Support\StoreReport;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The store reports due this hour (StoreReport::due): for each active shop whose report is on, at 08:00 on its own clock,
 * daily, on Mondays or on the 1st, the report of the period just ended, to its owners, once (admin.report_sent keeps
 * which period went last). A period with no searches and no questions sends nothing.
 */
final class SendStoreReports
{
    public function __construct(private readonly StoreReport $reports) {}

    /** @return int the reports sent */
    public function handle(?CarbonInterface $now = null, ?string $onlyShop = null): int
    {
        // nothing goes to any store until the team turns the reports on (admin.reports_sending): the first ones are seen first
        if ((int) Settings::get('admin.reports_sending') !== 1) {
            return 0;
        }
        $now = CarbonImmutable::instance($now ?? now());
        $sent = 0;

        $shops = Shop::query()->where('status', ShopStatus::Active)
            ->when($onlyShop !== null, fn ($q) => $q->where(fn ($w) => $w->where('id', $onlyShop)->orWhere('slug', $onlyShop)))
            ->orderBy('id')->get();

        foreach ($shops as $shop) {
            $frequency = StoreReport::frequency($shop);
            $local = $now->setTimezone((string) ($shop->timezone ?: 'Asia/Jerusalem'));
            if (! StoreReport::due($frequency, $local)) {
                continue;
            }
            $key = StoreReport::period($frequency, $local)['key'];
            if ((string) Settings::get('admin.report_sent', (string) $shop->id) === $key) {
                continue;
            }

            try {
                $report = $this->reports->of($shop, $frequency, $now);
                $emails = ReportSettings::ownerEmails($shop);
                if ($report !== null && $emails !== []) {
                    foreach ($emails as $email) {
                        Mail::to($email)->locale($report['locale'])->queue(new StoreReportMail($report));
                    }
                    $sent++;
                }
                // nothing to report, or no one to send it to: this period is done all the same
                Settings::set('admin.report_sent', $key, (string) $shop->id);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $sent;
    }
}
