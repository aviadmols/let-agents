<?php

namespace App\Modules\Analytics\Actions;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Analytics\Models\AnalyticsEvent;
use App\Modules\Analytics\Support\BeaconSchema;
use App\Modules\Connections\Models\StoreConnection;

/**
 * Stores one beacon from the storefront widget. The beacon is checked against the event spec
 * first; anything the spec does not allow is refused whole. Repeated event IDs are ignored, so
 * a beacon sent twice counts once.
 */
final class RecordBeacon
{
    /** Events stamped further from the server clock than this get the server's time. */
    private const MAX_CLOCK_SKEW_SECONDS = 2 * 86400;

    public function __construct(
        private readonly BeaconSchema $schema,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @return array{accepted: int, problems: list<string>}
     */
    public function handle(StoreConnection $connection, string $raw): array
    {
        $decoded = json_decode($raw);

        if (! is_object($decoded)) {
            return ['accepted' => 0, 'problems' => ['not_json']];
        }

        $problems = $this->schema->problems($decoded);

        if ($problems !== []) {
            return ['accepted' => 0, 'problems' => $problems];
        }

        $beacon = json_decode($raw, true);

        if ($beacon['shop'] !== $connection->shop_id) {
            return ['accepted' => 0, 'problems' => ['other_shop']];
        }

        if (! Features::enabled('analytics.collect', $connection->shop_id)) {
            return ['accepted' => 0, 'problems' => []];
        }

        $visitor = self::visitorHash($connection->shop_id, (string) $beacon['vid']);
        $now = now();
        $rows = [];

        foreach ($beacon['events'] as $event) {
            $ts = intdiv((int) $event['ts'], 1000);
            $page = $event['page'];
            $data = (array) ($event['data'] ?? []);

            $rows[] = [
                'shop_id' => $connection->shop_id,
                'event_id' => (string) $event['id'],
                'type' => (string) $event['type'],
                'page_type' => (string) $page['type'],
                'page_path' => mb_substr((string) $page['path'], 0, 512),
                'product_external_id' => $page['product_id'] ?? null,
                'content_external_id' => $page['content_id'] ?? null,
                // The product or article a click or add was about. WordPress numbers both from one sequence.
                'item_external_id' => $data['product_id'] ?? $data['content_id'] ?? null,
                'model' => $event['candidate']['model'] ?? null,
                'candidate_id' => $event['candidate']['id'] ?? null,
                'slot' => $event['candidate']['slot'] ?? null,
                'source' => $data['source'] ?? null,
                'result' => $data['result'] ?? null,
                'quantity' => $data['quantity'] ?? null,
                'visitor_hash' => $visitor,
                'session_id' => (string) $beacon['session'],
                'preview' => (bool) ($beacon['preview'] ?? false),
                'holdout' => (bool) $beacon['holdout'],
                'occurred_at' => abs($now->timestamp - $ts) > self::MAX_CLOCK_SKEW_SECONDS ? $now : $now->copy()->setTimestamp($ts),
                'created_at' => $now,
            ];
        }

        $accepted = $this->tenant->run($connection->shop_id, fn (): int => AnalyticsEvent::query()->insertOrIgnore($rows));

        return ['accepted' => $accepted, 'problems' => []];
    }

    /** Visitors are stored only as a hash salted per shop (see AnalyticsEvent::visitorHash). */
    public static function visitorHash(string $shopId, string $visitorId): string
    {
        return AnalyticsEvent::visitorHash($shopId, $visitorId);
    }
}
