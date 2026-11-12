<?php

namespace App\Modules\Admin\Support;

use App\Core\Facades\Settings;
use App\Core\Localization\Money;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\PoorAnswers;
use App\Modules\Admin\Contracts\TodayFix;
use App\Modules\Admin\Filament\Merchant\Pages\Improvements;
use App\Modules\Admin\Filament\Merchant\Pages\ReportSettings;
use App\Modules\Admin\Filament\Merchant\Pages\Searches;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * The store report the owner gets by email (Tomer's design, emails/weekly-report.html and its spec, 12.11): how many
 * searched and asked and how many found, the words searched most and the one rising, what they could not find with the
 * fix for it, what went to the cart through search, and the money last. Daily, weekly (the default), monthly or off, as
 * the owner chose (admin.report_frequency), at 08:00 on the store's clock: every day, on Mondays, on the 1st. Read from
 * the places Today reads (Home, Today): code only, nothing new is counted, no model.
 */
class StoreReport
{
    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    public const OFF = 'off';

    public const FREQUENCIES = [self::DAILY, self::WEEKLY, self::MONTHLY, self::OFF];

    /** The hour on the store's clock a report goes out. */
    public const HOUR = 8;

    /** Fewer searches and questions than this: the short report (the head, the top three words, one thing to fix). */
    public const QUIET = 50;

    /** A change is shown only when the period before had at least this many. */
    public const CHANGE_FROM = 20;

    /** A word rises when searched at least this many times. */
    public const RISING_FROM = 10;

    /** Fewer searches than this the period before: the word is new, not "N times more". */
    public const FEW_BEFORE = 3;

    public function __construct(private readonly Home $home, private readonly Today $today) {}

    /** How often this shop's report goes out, as its owner chose. */
    public static function frequency(Shop $shop): string
    {
        $chosen = (string) Settings::get('admin.report_frequency', (string) $shop->id);

        return in_array($chosen, self::FREQUENCIES, true) ? $chosen : self::WEEKLY;
    }

    /** Whether a report of this frequency goes out at this moment on the store's clock: 08:00 daily, on Mondays, on the 1st. */
    public static function due(string $frequency, CarbonInterface $local): bool
    {
        return $local->hour === self::HOUR && match ($frequency) {
            self::DAILY => true,
            self::WEEKLY => $local->isMonday(),
            self::MONTHLY => $local->day === 1,
            default => false,
        };
    }

    /**
     * The period a report covers, ending at the start of today on the store's clock, and the one of the same length
     * before it: yesterday, the last seven days, or the month since the same day of the last one (on the 1st, the month
     * just ended). Its key says which report it is, so none goes out twice.
     *
     * @return array{since: CarbonImmutable, until: CarbonImmutable, before: CarbonImmutable, key: string}
     */
    public static function period(string $frequency, CarbonInterface $local): array
    {
        $until = CarbonImmutable::instance($local)->startOfDay();
        $since = match ($frequency) {
            self::DAILY => $until->subDay(),
            self::MONTHLY => $until->subMonthNoOverflow(),
            default => $until->subDays(7),
        };
        $before = $since->sub($since->diff($until));

        return ['since' => $since, 'until' => $until, 'before' => $before, 'key' => $frequency.':'.$since->toDateString()];
    }

    /**
     * The report's content, or null when nothing happened in the period (search not in use yet): no report then.
     *
     * @return array<string, mixed>|null
     */
    public function of(Shop $shop, string $frequency, ?CarbonInterface $now = null): ?array
    {
        $frequency = in_array($frequency, [self::DAILY, self::WEEKLY, self::MONTHLY], true) ? $frequency : self::WEEKLY;
        $local = CarbonImmutable::instance($now ?? now())->setTimezone((string) ($shop->timezone ?: 'Asia/Jerusalem'));
        ['since' => $since, 'until' => $until, 'before' => $before] = self::period($frequency, $local);
        // what Today and Home read, in UTC
        [$sinceUtc, $untilUtc, $beforeUtc] = [$since->utc(), $until->utc(), $before->utc()];

        $found = $this->today->found($shop, $sinceUtc, $untilUtc);
        $searches = (int) ($found['searches'] ?? 0);
        $questions = (int) ($found['questions'] ?? 0);
        if ($searches + $questions === 0) {
            return null;
        }
        $was = $this->today->found($shop, $beforeUtc, $sinceUtc);
        $asked = $searches + $questions;
        $wasAsked = (int) ($was['searches'] ?? 0) + (int) ($was['questions'] ?? 0);
        $foundPct = (int) round(100 * (int) ($found['found'] ?? 0) / $asked);
        $wasPct = $wasAsked === 0 ? null : (int) round(100 * (int) ($was['found'] ?? 0) / $wasAsked);
        $quiet = $asked < self::QUIET;

        $days = max(1, (int) round($since->diffInDays($until)));
        $numbers = $this->home->numbers($shop, $days, $sinceUtc, $untilUtc);
        $currency = (string) ($numbers['currency'] ?? ($shop->currency ?: 'ILS'));
        $revenue = (float) ($numbers['revenue']['now'] ?? 0);

        $top = $this->today->top($shop, $sinceUtc, $untilUtc, 25);
        $fixes = array_slice($this->today->fixes($shop), 0, $quiet ? 1 : 3);

        return [
            'shop' => (string) $shop->name,
            'frequency' => $frequency,
            'locale' => $shop->content_locale === 'he' ? 'he' : 'en',
            'range' => self::range($since, $until, $frequency),
            'quiet' => $quiet,
            'searches' => $searches,
            'questions' => $questions,
            'found_pct' => $foundPct,
            'changes' => [
                'searches' => self::change($searches, (int) ($was['searches'] ?? 0), $quiet),
                'questions' => self::change($questions, (int) ($was['questions'] ?? 0), $quiet),
                'found' => $wasPct === null || $wasAsked < self::CHANGE_FROM || $quiet ? null : ['dir' => $foundPct <=> $wasPct, 'pts' => abs($foundPct - $wasPct)],
            ],
            'top' => array_map(fn (array $t): array => ['query' => self::clean((string) $t['query']), 'searches' => (int) $t['searches'], 'found' => $t['found'] === null ? null : self::clean((string) $t['found'])], array_slice($top, 0, $quiet ? 3 : 5)),
            'rising' => $quiet ? null : self::rising($top),
            'missing' => array_map(fn (TodayFix $f): array => ['title' => self::clean($f->title), 'text' => self::clean($f->text), 'action' => $f->action, 'url' => $f->url ?: Improvements::getUrl(panel: 'merchant', tenant: $shop)], $fixes),
            'sold' => $quiet ? [] : array_map(fn (array $s): array => ['title' => self::clean((string) $s['title']), 'adds' => (int) $s['adds']], array_slice($this->today->sold($shop, $sinceUtc, $untilUtc, 3), 0, 3)),
            'money' => $quiet || $revenue <= 0 ? null : Money::total($revenue, $currency),
            'adds' => (int) ($numbers['adds']['now'] ?? 0),
            'orders' => (int) ($numbers['orders']['now'] ?? 0),
            // the answers shoppers said did not help, with the two ways to fix them (Aviad, 12.11)
            'poor' => array_map(fn (array $p): array => ['question' => self::clean($p['question']), 'answer' => self::clean($p['answer']), 'downs' => (int) $p['downs']], $this->poor($shop, $sinceUtc, $untilUtc)),
            'urls' => [
                'panel' => Searches::getUrl(panel: 'merchant', tenant: $shop),
                'fixes' => Improvements::getUrl(panel: 'merchant', tenant: $shop),
                'settings' => ReportSettings::getUrl(panel: 'merchant', tenant: $shop),
                // the questions screen (each answer edited there) and the store's knowledge (what it knows about itself)
                'answers' => Route::has('filament.merchant.pages.assistant.questions') ? route('filament.merchant.pages.assistant.questions', ['tenant' => $shop->slug]) : null,
                'knowledge' => Route::has('filament.merchant.pages.about-store') ? route('filament.merchant.pages.about-store', ['tenant' => $shop->slug]) : null,
            ],
        ];
    }

    /** At most this many answers shoppers said did not help. */
    public const POOR_SHOWN = 3;

    /** @return list<array{question: string, answer: string, downs: int}> */
    private function poor(Shop $shop, CarbonInterface $since, CarbonInterface $until): array
    {
        foreach (app()->tagged(PoorAnswers::TAG) as $source) {
            try {
                return app(TenantContext::class)->run((string) $shop->id, fn (): array => $source->poor($shop, $since, $until, self::POOR_SHOWN));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return [];
    }

    /**
     * A change from the period before, or none when the one before was too small to mean anything: up, or a drop (shown
     * in grey, never red).
     *
     * @return array{dir: int, pct: int}|null
     */
    public static function change(int $now, int $before, bool $quiet = false): ?array
    {
        if ($quiet || $before < self::CHANGE_FROM) {
            return null;
        }
        $pct = (int) round(100 * ($now - $before) / $before);

        return ['dir' => $pct <=> 0, 'pct' => abs($pct)];
    }

    /**
     * The word whose searches rose the most, searched at least RISING_FROM times: how many times more than the period
     * before, or new (none before).
     *
     * @param  list<array{query: string, searches: int, before: int}>  $top
     * @return array{query: string, times: int|null, searches: int}|null
     */
    public static function rising(array $top): ?array
    {
        $best = null;
        foreach ($top as $t) {
            $now = (int) $t['searches'];
            // a few searches before say nothing of how much it rose ("פי 103" over one search): it is new
            $before = (int) $t['before'] < self::FEW_BEFORE ? 0 : (int) $t['before'];
            // a code or a number alone ("35059", a product's SKU) is no word that rises
            if ($now < self::RISING_FROM || ($before > 0 && $now < 2 * $before) || preg_match('/\p{L}{2}/u', (string) $t['query']) !== 1) {
                continue;
            }
            $score = $before === 0 ? $now : $now / $before;
            if ($best === null || $score > $best['score']) {
                $best = ['query' => self::clean((string) $t['query']), 'times' => $before === 0 ? null : (int) floor($now / $before), 'searches' => $now, 'score' => $score];
            }
        }

        return $best === null ? null : array_diff_key($best, ['score' => true]);
    }

    /** The period as the head shows it: "11.11" for a day, "3.11–9.11" for a week or a month. */
    private static function range(CarbonImmutable $since, CarbonImmutable $until, string $frequency): string
    {
        $last = $until->subDay();

        return $frequency === self::DAILY ? $since->format('j.n') : $since->format('j.n').'–'.$last->format('j.n');
    }

    /** Shoppers' words without what may tell who they are: an email, a phone number, a long number (an order). */
    public static function clean(string $text): string
    {
        $text = (string) preg_replace('/\S+@\S+\.\S+/u', '…', $text);
        $text = (string) preg_replace('/(?:\+?972|\b0)[\s-]?\d{1,2}[\s-]?\d{3}[\s-]?\d{4}\b/u', '…', $text);
        $text = (string) preg_replace('/\b\d{7,}\b/u', '…', $text);

        return trim(mb_substr($text, 0, 160));
    }
}
