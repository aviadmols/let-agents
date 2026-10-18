<?php

namespace App\Modules\Analytics\Actions;

use App\Core\Facades\Features;
use App\Core\Http\Visit;
use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Models\AnalyticsVisit;
use App\Modules\Analytics\Models\AnalyticsVisitStep;
use App\Modules\Connections\Models\StoreConnection;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * A batch from the search window's visit beacon: the steps of one visit, in order. Code only: each
 * field is checked, unknown ones are dropped, and of the address the shopper came in on only the
 * named campaign parameters are kept (utm_*, gclid, fbclid, ref), never the rest. The visit is made
 * on its first step and its counters follow; a step sent twice is kept once.
 */
final class RecordVisit
{
    /** The campaign parameters a visit keeps. Anything else in the address is never read. */
    public const PARAMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'ref'];

    private const MAX_STEPS = 30;

    private const MAX_SEARCHES = 10;

    private const MAX_ASKS = 5;

    public function __construct(private readonly TenantContext $tenant) {}

    /** @return array{stored: int, problems: list<string>} */
    public function handle(StoreConnection $connection, Request $request, string $raw): array
    {
        $body = json_decode($raw, true);

        if (! is_array($body) || ($body['v'] ?? null) !== 1) {
            return ['stored' => 0, 'problems' => ['not a visit beacon']];
        }

        $sid = (string) ($body['sid'] ?? '');
        $vid = (string) ($body['vid'] ?? '');

        if (preg_match('/^[A-Za-z0-9_-]{8,64}$/', $sid) !== 1) {
            return ['stored' => 0, 'problems' => ['sid']];
        }

        if (! Features::enabled('analytics.sessions', $connection->shop_id)) {
            return ['stored' => 0, 'problems' => []];
        }

        $steps = $this->steps((array) ($body['steps'] ?? []));

        if ($steps === []) {
            return ['stored' => 0, 'problems' => ['no steps']];
        }

        $shopId = (string) $connection->shop_id;

        return $this->tenant->run($shopId, function () use ($shopId, $sid, $vid, $body, $steps, $request): array {
            $visit = $this->visit($shopId, $sid, $vid, (array) ($body['first'] ?? []), $steps, (bool) ($body['preview'] ?? false), $request);
            $known = AnalyticsVisitStep::query()->where('visit_id', $visit->id)->whereIn('step_id', array_column($steps, 'step_id'))->pluck('step_id')->all();
            $new = array_values(array_filter($steps, fn (array $s): bool => ! in_array($s['step_id'], $known, true)));

            if ($new === []) {
                return ['stored' => 0, 'problems' => []];
            }

            AnalyticsVisitStep::query()->insertOrIgnore(array_map(fn (array $s): array => ['shop_id' => $shopId, 'visit_id' => $visit->id, ...$s], $new));
            $this->count($visit, $new);

            return ['stored' => count($new), 'problems' => []];
        });
    }

    /**
     * Where a visit came from, by its campaign parameters first, then the address it came from.
     *
     * @param  array<string, string>  $params
     * @return array{source: string, paid: bool, campaign: string|null}
     */
    public static function source(?string $referrer, array $params): array
    {
        $tag = mb_strtolower((string) ($params['utm_source'] ?? ''));
        $medium = mb_strtolower((string) ($params['utm_medium'] ?? ''));
        $host = mb_strtolower((string) $referrer);

        $source = match (true) {
            $tag !== '' && preg_match('/^(google|adwords|gads)/', $tag) === 1 => 'google',
            $tag !== '' && preg_match('/^(facebook|fb|meta)/', $tag) === 1 => 'facebook',
            $tag !== '' && preg_match('/^(instagram|ig)$|^instagram/', $tag) === 1 => 'instagram',
            $tag !== '' && preg_match('/^(whatsapp|wa)$|^whatsapp/', $tag) === 1 => 'whatsapp',
            $tag !== '' && preg_match('/newsletter|email|mail|klaviyo|mailchimp|activetrail|smoove/', $tag.$medium) === 1 => 'email',
            isset($params['gclid']) => 'google',
            isset($params['fbclid']) && str_contains($host, 'instagram') => 'instagram',
            isset($params['fbclid']) => 'facebook',
            $host === '' => $tag !== '' ? 'other' : 'direct',
            preg_match('/(^|\.)google\./', $host) === 1 => 'google',
            preg_match('/(^|\.)(facebook\.com|fb\.com|fb\.me)$/', $host) === 1 => 'facebook',
            preg_match('/(^|\.)instagram\.com$/', $host) === 1 => 'instagram',
            preg_match('/(^|\.)(wa\.me|whatsapp\.com)$/', $host) === 1 => 'whatsapp',
            preg_match('/mail\.|outlook\.|gmail/', $host) === 1 => 'email',
            default => 'other',
        };

        return [
            'source' => $source,
            'paid' => isset($params['gclid']) || preg_match('/cpc|ppc|paid|ads?$|display|sponsored/', $medium) === 1,
            'campaign' => $params['utm_campaign'] ?? ($params['ref'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $first
     * @param  list<array<string, mixed>>  $steps
     */
    private function visit(string $shopId, string $sid, string $vid, array $first, array $steps, bool $preview, Request $request): AnalyticsVisit
    {
        $existing = AnalyticsVisit::query()->where('session_id', $sid)->first();

        if ($existing !== null) {
            return $existing;
        }

        $visitor = preg_match('/^anon-[A-Za-z0-9_-]{16,64}$/', $vid) === 1 ? AnalyticsEvent::visitorHash($shopId, $vid) : null;
        $params = self::params((array) ($first['params'] ?? []));
        $referrer = is_string($first['ref'] ?? null) ? Visit::host((string) $first['ref']) : null;
        $agent = (string) $request->userAgent();
        $start = min(array_map(fn (array $s): Carbon => $s['at'], $steps));

        try {
            return AnalyticsVisit::query()->create([
                'session_id' => $sid,
                'visitor_hash' => $visitor,
                'visit_number' => $visitor === null ? 1 : 1 + AnalyticsVisit::query()->where('visitor_hash', $visitor)->count(),
                'started_at' => $start,
                'last_at' => $start,
                'landing' => is_string($first['landing'] ?? null) ? (self::path((string) $first['landing']) ?? $steps[0]['path']) : $steps[0]['path'],
                'referrer' => $referrer,
                ...self::source($referrer, $params),
                'params' => $params === [] ? null : $params,
                'device' => $agent === '' ? null : Visit::device($agent),
                'browser' => $agent === '' ? null : Visit::browser($agent),
                'os' => $agent === '' ? null : Visit::os($agent),
                'ip' => $request->ip(),
                'preview' => $preview,
            ]);
        } catch (QueryException) {
            // Two batches of one visit at once: the other one made it.
            return AnalyticsVisit::query()->where('session_id', $sid)->firstOrFail();
        }
    }

    /** @param list<array<string, mixed>> $new */
    private function count(AnalyticsVisit $visit, array $new): void
    {
        $did = (array) ($visit->did ?? []);
        $searches = (array) ($visit->searches ?? []);
        $asks = (array) ($visit->asks ?? []);

        foreach ($new as $step) {
            $kind = $step['kind'];

            if (in_array($kind, AnalyticsVisit::DID, true) && ! in_array($kind, $did, true)) {
                $did[] = $kind;
            }
            if ($kind === 'search' && $step['label'] !== null && ! in_array($step['label'], $searches, true)) {
                $searches[] = $step['label'];
            }
            if ($kind === 'ask' && $step['label'] !== null && ! in_array($step['label'], $asks, true)) {
                $asks[] = $step['label'];
            }
        }

        $last = max([$visit->last_at, ...array_map(fn (array $s): Carbon => $s['at'], $new)]);

        $visit->forceFill([
            'last_at' => $last,
            'pages' => $visit->pages + count(array_filter($new, fn (array $s): bool => $s['kind'] === 'view')),
            'steps' => $visit->steps + count($new),
            'did' => array_values($did),
            'searches' => array_slice(array_values($searches), 0, self::MAX_SEARCHES),
            'asks' => array_slice(array_values($asks), 0, self::MAX_ASKS),
            'words' => mb_substr(mb_strtolower(implode(' | ', [...$searches, ...$asks])), 0, 4000) ?: null,
        ])->save();
    }

    /**
     * The steps, each checked: a known kind, a time near now, a page path without its query, words cut short.
     *
     * @param  array<int, mixed>  $sent
     * @return list<array{step_id: string, kind: string, at: Carbon, path: string|null, page_type: string|null, item: string|null, label: string|null, number: int|null, value: float|null}>
     */
    private function steps(array $sent): array
    {
        $steps = [];
        $now = now();

        foreach (array_slice($sent, 0, self::MAX_STEPS) as $step) {
            if (! is_array($step) || ! in_array($step['kind'] ?? null, AnalyticsVisitStep::KINDS, true) || preg_match('/^[A-Za-z0-9_-]{6,32}$/', (string) ($step['id'] ?? '')) !== 1) {
                continue;
            }

            $at = Carbon::createFromTimestampMs((int) ($step['ts'] ?? 0));

            // A clock far off: the server's time.
            if ($at->lt($now->copy()->subDays(2)) || $at->gt($now->copy()->addHour())) {
                $at = $now->copy();
            }

            $label = is_string($step['label'] ?? null) ? trim((string) preg_replace('/[\p{C}]+/u', ' ', $step['label'])) : '';
            $item = is_scalar($step['item'] ?? null) && preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', (string) $step['item']) === 1 ? (string) $step['item'] : null;
            $type = in_array($step['type'] ?? null, ['product', 'category', 'cart', 'checkout', 'search', 'content', 'home', 'other'], true) ? (string) $step['type'] : null;

            $steps[] = [
                'step_id' => (string) $step['id'],
                'kind' => (string) $step['kind'],
                'at' => $at,
                'path' => is_string($step['path'] ?? null) ? self::path((string) $step['path']) : null,
                'page_type' => $type,
                'item' => $item,
                'label' => $label === '' ? null : mb_substr($label, 0, 200),
                'number' => is_numeric($step['n'] ?? null) ? max(-1, min(1_000_000, (int) $step['n'])) : null,
                'value' => is_numeric($step['value'] ?? null) ? round(max(0, min(10_000_000, (float) $step['value'])), 2) : null,
            ];
        }

        return $steps;
    }

    /**
     * @param  array<string, mixed>  $sent
     * @return array<string, string>
     */
    private static function params(array $sent): array
    {
        $kept = [];

        foreach (self::PARAMS as $name) {
            $value = $sent[$name] ?? null;

            if (is_string($value) && preg_match('/^[\p{L}\p{N}_.~%+:\/ -]{1,120}$/u', $value) === 1) {
                $kept[$name] = $value;
            }
        }

        return $kept;
    }

    /** A page's path, without what follows it: where emails and tracking ids leak. */
    private static function path(string $path): ?string
    {
        $path = (string) preg_replace('/[?#].*$/s', '', trim($path));

        return str_starts_with($path, '/') ? mb_substr($path, 0, 300) : null;
    }
}
