<?php

namespace App\Modules\Leads\Http\Controllers;

use App\Core\Facades\Features;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Leads\Actions\RecordLead;
use App\Modules\Leads\Models\LeadFlow;
use App\Modules\Leads\Support\FlowMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/widget/{site}/lead   {"vid": "...", "page": {...}, "given": {...}, "consent": true}
 *
 * One step of the flow. The widget sends everything it has been told so far and is told what to
 * ask next; nothing is kept between calls until the consent arrives, so a reader who closes the
 * tab halfway leaves nothing behind.
 *
 * Sent as text/plain from the storefront so the browser sends no preflight; the body is JSON.
 */
final class LeadStepController
{
    private const VISITOR_PATTERN = '/^anon-[A-Za-z0-9_-]{16,64}$/';

    private const ID_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/';

    public function __invoke(Request $request, RecordLead $record, TenantContext $tenant, string $site): JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }

        $origin = $request->header('Origin');

        if ($origin === null || ! $connection->allowsOrigin($origin)) {
            return response()->json(['error' => 'other_origin'], 403);
        }

        if (! Features::enabled('leads.enabled', $connection->shop_id)) {
            return response()->json(['error' => 'not_enabled'], 403);
        }

        $body = json_decode((string) $request->getContent(), true);
        $body = is_array($body) ? $body : [];
        $shopId = $connection->shop_id;

        $flow = $tenant->run($shopId, fn (): ?LeadFlow => LeadFlow::inForce($shopId));

        if ($flow === null || ! $flow->canReachAnyone()) {
            return response()->json(['error' => 'no_flow'], 409);
        }

        $pageType = ($body['type'] ?? '') === 'content' ? 'content' : 'product';
        $pageId = (string) ($body['id'] ?? '');

        if (preg_match(self::ID_PATTERN, $pageId) !== 1) {
            return response()->json(['error' => 'invalid_page'], 422);
        }

        // Only the keys this flow actually asks for, so nothing else can be posted into a lead.
        $given = [];
        $sent = is_array($body['given'] ?? null) ? $body['given'] : [];

        foreach ($flow->asked() as $field) {
            if (! array_key_exists($field['key'], $sent)) {
                continue;
            }

            $checked = FlowMachine::check($field, (string) $sent[$field['key']], $shopId);

            if (! $checked['ok']) {
                return response()->json(['data' => [
                    'state' => FlowMachine::FIELD,
                    'field' => $field,
                    'error' => $checked['reason'],
                ]]);
            }

            $given[$field['key']] = $checked['value'];
        }

        // "No thanks" to a field after a phone or an email was given ("at": the step it was said on) is not a
        // no to all of it: what they gave is kept if they agree, and the widget sends "enough" from then on.
        // A no to the consent itself, or from a widget that does not say where, keeps nothing.
        if (($body['declined'] ?? false) === true) {
            if (($body['at'] ?? null) === FlowMachine::FIELD && FlowMachine::reaches($flow, $given)) {
                return response()->json(['data' => FlowMachine::next($flow, $given, enough: true) + ['offer' => $flow->offer, 'consent' => $flow->consent, 'enough' => true]]);
            }

            return response()->json(['data' => FlowMachine::next($flow, $given, declined: true)]);
        }

        $consented = ($body['consent'] ?? false) === true;
        $next = FlowMachine::next($flow, $given, consented: $consented, enough: ($body['enough'] ?? false) === true);

        if ($next['state'] !== FlowMachine::DONE) {
            return response()->json(['data' => $next + ['offer' => $flow->offer, 'consent' => $flow->consent]]);
        }

        $visitor = (string) ($body['vid'] ?? '');
        $result = $record->handle(
            shopId: $shopId,
            flow: $flow,
            given: $given,
            pageType: $pageType,
            pageExternalId: $pageId,
            ctaId: is_string($body['cta'] ?? null) ? mb_substr($body['cta'], 0, 40) : null,
            visitorHash: preg_match(self::VISITOR_PATTERN, $visitor) === 1 ? hash('sha256', $shopId.'|'.$visitor) : null,
            questionsAsked: max(0, min(20, (int) ($body['asked'] ?? 0))),
        );

        if ($result['lead'] === null) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json(['data' => [
            'state' => FlowMachine::DONE,
            'field' => null,
            'done' => $next['total'],
            'total' => $next['total'],
            'promise' => $flow->promise,
        ]])->header('Cache-Control', 'no-store');
    }
}
