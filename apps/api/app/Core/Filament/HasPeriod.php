<?php

namespace App\Core\Filament;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * The period a merchant screen shows, as the designer's panel offers it on every screen that counts:
 * 7, 30 or 90 days, or dates the manager chose (presets: today, yesterday, this week, this month, last
 * month, this year). In the address, so a link keeps it. Drawn by the view admin::merchant.period.
 */
trait HasPeriod
{
    /** 7, 30 or 90; 0 when the manager chose dates. */
    #[Url]
    public int $days = 30;

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    /** @return list<int> */
    public static function periods(): array
    {
        return [7, 30, 90];
    }

    public function setDays(int $days): void
    {
        $this->days = in_array($days, self::periods(), true) ? $days : 30;
        $this->from = '';
        $this->to = '';
        $this->periodChanged();
    }

    /** Dates the manager chose: from the first day to the last, both included, a year at most. */
    public function setRange(string $from, string $to): void
    {
        $a = self::periodDate($from);
        $b = self::periodDate($to);

        if ($a === null || $b === null || $b->lt($a) || $a->diffInDays($b) > 366) {
            return;
        }

        $this->days = 0;
        $this->from = $a->toDateString();
        $this->to = $b->toDateString();
        $this->periodChanged();
    }

    /** @return array{0: Carbon, 1: Carbon} the period's start, and its end (exclusive) */
    public function range(): array
    {
        if ($this->days === 0 && ($a = self::periodDate($this->from)) !== null && ($b = self::periodDate($this->to)) !== null && $b->gte($a)) {
            return [Carbon::instance($a)->startOfDay(), Carbon::instance($b)->addDay()->startOfDay()];
        }

        $days = in_array($this->days, self::periods(), true) ? $this->days : 30;

        return [now()->subDays($days)->startOfDay(), now()];
    }

    /** "the last 30 days", or "18.10", or "1.10 to 10.10", for a subheading. */
    public function periodLabel(): string
    {
        if ($this->days !== 0) {
            return __('core::period.days', ['days' => $this->days]);
        }

        [$since, $until] = $this->range();
        $last = $until->copy()->subDay();

        return $since->isSameDay($last)
            ? __('core::period.day', ['from' => $since->format('j.n')])
            : __('core::period.range', ['from' => $since->format('j.n'), 'to' => $last->format('j.n')]);
    }

    /** What a screen does when the period changes: start its list again, say. */
    protected function periodChanged(): void {}

    private static function periodDate(string $value): ?CarbonInterface
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? Carbon::createFromFormat('Y-m-d', $value)->startOfDay() : null;
    }
}
