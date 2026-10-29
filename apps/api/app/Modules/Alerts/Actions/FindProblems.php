<?php

namespace App\Modules\Alerts\Actions;

use App\Core\Facades\Settings;
use App\Modules\Ai\Contracts\SpendGuard;
use App\Modules\Alerts\Support\Problem;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Enums\ShopStatus;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * What is wrong in the system now, read from what it already records, code only: a monthly budget near its end or
 * spent (the platform's and each live shop's), a kind of work failing again and again in the last hour, background
 * work stopped, runs that started and never ended, a queue nobody takes work from, a store not synced for too long.
 * Each comes back once per sweep while it lasts; TellProblems decides what is news.
 */
final class FindProblems
{
    /** A refused call is kept here by its cap's scope (RememberSpendCap): the month's sum may not show it yet. */
    public const CAP = 'alerts:cap:';

    /** Failures counted over this many minutes. */
    private const WINDOW = 60;

    /** The run of a store's sync that worked (Catalog's SyncCatalog::ACTION). */
    private const SYNC = 'catalog.sync';

    public function __construct(private readonly SpendGuard $guard) {}

    /** @return list<Problem> */
    public function handle(): array
    {
        return [...$this->budgets(), ...$this->queue(), ...$this->failedRuns(), ...$this->failedJobs(), ...$this->stuckRuns(), ...$this->syncs()];
    }

    /** @return list<Problem> */
    private function budgets(): array
    {
        $problems = [];
        $scopes = ['platform' => null] + Shop::query()->where('status', ShopStatus::Active)->orderBy('name')->get()->keyBy('id')->all();

        foreach ($scopes as $scope => $shop) {
            [$spent, $cap] = $shop === null ? [$this->guard->spentThisMonth(), $this->guard->cap()] : [$this->guard->spentThisMonthFor($shop->id), $this->guard->capFor($shop->id)];
            if ($cap <= 0) {
                continue;
            }
            $hit = Cache::get(self::CAP.$scope);
            $reached = $spent >= $cap || (is_array($hit) && abs((float) ($hit['cap'] ?? 0) - $cap) < 0.005);
            $percent = (int) floor($spent / $cap * 100);
            if (! $reached && $percent < (int) Settings::get('alerts.budget_warn_percent')) {
                continue;
            }

            $kind = $reached ? 'reached' : 'near';
            $who = $shop === null ? 'platform' : 'shop';
            $with = [
                'shop' => $shop?->name ?? '',
                'spent' => number_format($spent, 2),
                'cap' => number_format($cap, 2),
                'percent' => (string) $percent,
                'reset' => now()->utc()->startOfMonth()->addMonth()->timezone('Asia/Jerusalem')->format('j.n'),
                'pace' => ($on = $this->runsOutOn($spent, $cap)) === '' ? (string) __('alerts::mail.budget.pace_ok') : (string) __('alerts::mail.budget.pace', ['date' => $on]),
                'shoppers' => (string) __('alerts::mail.budget.shoppers_'.($shop === null || Settings::get('ai.shop_cap_stops_shoppers', $shop->id) ? 'blocked' : 'served')),
            ];
            $problems[] = new Problem(
                key: 'budget:'.$kind.':'.$scope.':'.now()->utc()->format('Y-m').':'.$cap,
                title: (string) __("alerts::mail.budget.{$kind}.{$who}.title", $with),
                what: (string) __("alerts::mail.budget.{$kind}.{$who}.what", $with),
                next: (string) __("alerts::mail.budget.{$kind}.next", $with),
                link: self::link('filament.operator.pages.budgets'),
                urgent: $reached,
                until: now()->utc()->endOfMonth(),
            );
        }

        return $problems;
    }

    /** When the month's pace so far spends what is left, or '' when that is after the month ends. */
    private function runsOutOn(float $spent, float $cap): string
    {
        $days = max(1.0, now()->utc()->startOfMonth()->diffInHours(now()->utc()) / 24);
        $perDay = $spent / $days;
        if ($perDay <= 0 || $spent >= $cap) {
            return '';
        }
        $on = now()->addHours((int) ceil(($cap - $spent) / $perDay * 24));

        return $on->lt(now()->utc()->endOfMonth()) ? $on->timezone('Asia/Jerusalem')->format('j.n') : '';
    }

    /** @return list<Problem> */
    private function queue(): array
    {
        if (config('queue.default') !== 'database') {
            return [];
        }
        $jobs = DB::table((string) config('queue.connections.database.table', 'jobs'))->whereNull('reserved_at');
        $oldest = (clone $jobs)->min('available_at');
        if ($oldest === null || (int) $oldest > now()->subMinutes((int) Settings::get('alerts.queue_minutes'))->getTimestamp()) {
            return [];
        }

        $with = ['count' => (string) $jobs->where('available_at', '<=', now()->getTimestamp())->count(), 'at' => self::at(Carbon::createFromTimestamp((int) $oldest))];

        return [new Problem(
            key: 'queue:stalled',
            title: (string) __('alerts::mail.queue.title'),
            what: (string) __('alerts::mail.queue.what', $with),
            next: (string) __('alerts::mail.queue.next'),
            urgent: true,
        )];
    }

    /** @return list<Problem> */
    private function failedRuns(): array
    {
        $blocked = self::capMessages();
        $groups = [];
        foreach (Run::query()->where('status', RunStatus::Failed)->where('finished_at', '>=', now()->subMinutes(self::WINDOW))->orderBy('finished_at')->get(['action', 'error', 'shop_id']) as $run) {
            $error = trim((string) $run->error);
            // a refused call is the budget's news (budgets), not a failure of its own
            if (Str::startsWith($error, $blocked)) {
                continue;
            }
            $reason = self::reason($error);
            $group = &$groups[$run->action.':'.$reason];
            $group ??= ['action' => $run->action, 'reason' => $reason, 'count' => 0, 'shops' => [], 'error' => $error];
            $group['count']++;
            $group['shops'][(string) $run->shop_id] = true;
            unset($group);
        }

        $min = (int) Settings::get('alerts.failed_runs');
        $problems = [];
        foreach ($groups as $key => $group) {
            // a missing key fails every call that comes after it: once is enough to tell
            if ($group['count'] < $min && $group['reason'] !== 'no_key') {
                continue;
            }
            $with = ['action' => Run::labelFor('actions', $group['action']), 'count' => (string) $group['count'], 'minutes' => (string) self::WINDOW, 'shops' => self::shopNames(array_keys($group['shops']))];
            $problems[] = new Problem(
                key: 'runs:'.$key,
                title: (string) __('alerts::mail.runs.title', $with),
                what: trim(__('alerts::mail.runs.what', $with).' '.__('alerts::mail.reasons.'.$group['reason']).' '.__('alerts::mail.example', ['error' => Str::limit($group['error'], 180)])),
                next: (string) __('alerts::mail.runs.next.'.$group['reason']),
                link: self::link('filament.operator.resources.runs.index'),
            );
        }

        return $problems;
    }

    /** @return list<Problem> */
    private function failedJobs(): array
    {
        $table = (string) config('queue.failed.table', 'failed_jobs');
        if (! Schema::hasTable($table)) {
            return [];
        }

        $groups = [];
        foreach (DB::table($table)->where('failed_at', '>=', now()->subMinutes(self::WINDOW))->orderBy('failed_at')->get(['payload', 'exception']) as $row) {
            $name = class_basename((string) (json_decode((string) $row->payload, true)['displayName'] ?? 'job'));
            $first = (string) strtok((string) $row->exception, "\n");
            $groups[$name] ??= ['count' => 0, 'error' => $first];
            $groups[$name]['count']++;
        }

        $problems = [];
        foreach ($groups as $name => $group) {
            $with = ['job' => $name, 'count' => (string) $group['count'], 'minutes' => (string) self::WINDOW];
            $problems[] = new Problem(
                key: 'job:'.$name,
                title: (string) __('alerts::mail.jobs.title', $with),
                what: trim(__('alerts::mail.jobs.what', $with).' '.__('alerts::mail.reasons.'.self::reason($group['error'])).' '.__('alerts::mail.example', ['error' => Str::limit($group['error'], 180)])),
                next: (string) __('alerts::mail.jobs.next'),
            );
        }

        return $problems;
    }

    /** @return list<Problem> */
    private function stuckRuns(): array
    {
        $hours = (int) Settings::get('alerts.stuck_hours');
        $problems = [];
        // started in the last day and past their hours: older ones left open were told of, or are from before this
        foreach (Run::query()->where('status', RunStatus::Running)->where('started_at', '<', now()->subHours($hours))->where('started_at', '>=', now()->subHours($hours + 24))
            ->select('action', DB::raw('count(*) as runs'), DB::raw('min(started_at) as first'))->groupBy('action')->orderBy('action')->get() as $row) {
            $with = ['action' => Run::labelFor('actions', (string) $row->action), 'count' => (string) $row->runs, 'hours' => (string) $hours, 'at' => self::at(Carbon::parse((string) $row->first))];
            $problems[] = new Problem(
                key: 'stuck:'.$row->action,
                title: (string) __('alerts::mail.stuck.title', $with),
                what: (string) __('alerts::mail.stuck.what', $with),
                next: (string) __('alerts::mail.stuck.next'),
                link: self::link('filament.operator.resources.runs.index'),
            );
        }

        return $problems;
    }

    /** @return list<Problem> */
    private function syncs(): array
    {
        $hours = (int) Settings::get('alerts.sync_hours');
        $problems = [];
        foreach (Shop::query()->where('status', ShopStatus::Active)->orderBy('name')->get() as $shop) {
            $last = Run::query()->where('shop_id', $shop->id)->where('action', self::SYNC)->where('status', RunStatus::Succeeded)->max('started_at');
            // a shop never synced is still being set up: the first scan tells of itself
            if ($last === null || Carbon::parse((string) $last)->gt(now()->subHours($hours))) {
                continue;
            }
            $failed = Run::query()->where('shop_id', $shop->id)->where('action', self::SYNC)->where('status', RunStatus::Failed)->where('started_at', '>', $last)->latest('started_at')->value('error');
            $with = ['shop' => $shop->name, 'at' => self::at(Carbon::parse((string) $last)), 'hours' => (string) (int) Carbon::parse((string) $last)->diffInHours(now(), true), 'error' => Str::limit(trim((string) $failed), 180)];
            $problems[] = new Problem(
                key: 'sync:'.$shop->id.':'.Carbon::parse((string) $last)->getTimestamp(),
                title: (string) __('alerts::mail.sync.title', $with),
                what: trim(__('alerts::mail.sync.what', $with).' '.($failed !== null ? __('alerts::mail.sync.failed', $with) : __('alerts::mail.sync.not_tried'))),
                next: (string) __('alerts::mail.sync.next'),
                link: self::link('filament.operator.resources.runs.index'),
                urgent: true,
            );
        }

        return $problems;
    }

    /** What kind of failure an error is, for the words that explain it. */
    public static function reason(string $error): string
    {
        return match (true) {
            (bool) preg_match('/no_key|api key|unauthori[sz]ed|\b401\b|invalid.{0,10}key/i', $error) => 'no_key',
            (bool) preg_match('/rate.?limit|too many requests|429/i', $error) => 'rate_limited',
            (bool) preg_match('/not_json/i', $error) => 'not_json',
            (bool) preg_match('/MaxAttemptsExceeded|TimeoutExceeded|timed? ?out|Unable to read from stream|cURL error 28/i', $error) => 'timeout',
            (bool) preg_match('/SQLSTATE|QueryException|UniqueConstraintViolation/i', $error) => 'database',
            (bool) preg_match('/provider_error|overloaded|\b50[0-4]\b|server error/i', $error) => 'provider',
            default => 'other',
        };
    }

    /** @return list<string> how a refused call's message starts, in every language a run may hold it */
    private static function capMessages(): array
    {
        $starts = [];
        foreach (['he', 'en'] as $locale) {
            foreach (['ai::budget.cap_reached', 'ai::budget.shop_cap_reached'] as $key) {
                $starts[] = Str::before((string) __($key, [], $locale), ':spent');
            }
        }

        return array_values(array_filter(array_unique($starts)));
    }

    /** @param list<string> $ids */
    private static function shopNames(array $ids): string
    {
        $names = Shop::query()->whereIn('id', array_filter($ids))->orderBy('name')->pluck('name')->all();

        return $names === [] ? (string) __('alerts::mail.no_shop') : implode(', ', $names);
    }

    private static function at(Carbon $at): string
    {
        return $at->copy()->timezone('Asia/Jerusalem')->format('j.n H:i');
    }

    private static function link(string $route): ?string
    {
        return Route::has($route) ? route($route) : null;
    }
}
