<?php

namespace App\Core\Filament;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;

/**
 * The period a merchant screen shows, as the designer's panel offers it on every screen that counts:
 * 7, 30 or 90 days, or dates the manager chose (presets: today, yesterday, this week, this month, last
 * month, last month, this year). In the address, so a link keeps it. Drawn by the view admin::merchant.period.
 * A screen may see only so far back (historyDays: the shop's plan): nothing older is offered or shown.
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

    /** @return list<int> the periods this screen offers: those within how far back it sees */
    public function offeredPeriods(): array
    {
        $history = $this->historyDays();
        $offered = array_values(array_filter(self::periods(), fn (int $days): bool => $history === null || $days <= $history));

        return $offered === [] ? [self::periods()[0]] : $offered;
    }

    public function setDays(int $days): void
    {
        $offered = $this->offeredPeriods();
        $this->days = in_array($days, $offered, true) ? $days : min(30, max($offered));
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

        $history = $this->historyDays();

        if ($history !== null && $a->lt(now()->subDays($history)->startOfDay())) {
            $a = Carbon::instance(now()->subDays($history)->startOfDay());

            if ($b->lt($a)) {
                return;
            }
        }

        $this->days = 0;
        $this->from = $a->toDateString();
        $this->to = $b->toDateString();
        $this->periodChanged();
    }

    /** @return array{0: Carbon, 1: Carbon} the period's start, and its end (exclusive), never before how far back the screen sees */
    public function range(): array
    {
        [$since, $until] = $this->chosenRange();
        $history = $this->historyDays();

        return [$history === null ? $since : $since->max(now()->subDays($history)->startOfDay()), $until];
    }

    /** How far back this screen sees, in days; null: as far as the data goes. */
    protected function historyDays(): ?int
    {
        return null;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function chosenRange(): array
    {
        if ($this->days === 0 && ($a = self::periodDate($this->from)) !== null && ($b = self::periodDate($this->to)) !== null && $b->gte($a)) {
            return [Carbon::instance($a)->startOfDay(), Carbon::instance($b)->addDay()->startOfDay()];
        }

        $days = in_array($this->days, $this->offeredPeriods(), true) ? $this->days : min(30, max($this->offeredPeriods()));

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
