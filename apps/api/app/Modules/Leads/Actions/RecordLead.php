<?php

namespace App\Modules\Leads\Actions;

use App\Core\Tenancy\TenantContext;
use App\Modules\Leads\Jobs\SendLeadToTeam;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadFlow;
use App\Modules\Leads\Support\FlowMachine;
use App\Modules\Leads\Support\LeadFields;
use App\Modules\Shoppers\Contracts\ReadsContacts;

/**
 * Keeps somebody who asked to be contacted.
 *
 * Nothing is written before the consent, and the wording is copied onto the row rather than
 * pointed at, so a shop that rewords its consent tomorrow cannot change what somebody agreed to
 * today. A second lead from the same person on the same page updates the first instead of filling
 * a list with the same phone number six times.
 *
 * No model is called here and none ever will be: this is the one table in the platform with a
 * person in it.
 */
final class RecordLead
{
    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @param  array<string, string>  $given  field key => what they typed, already checked
     * @param  string|null  $question  the question that had no answer, when they left details about it
     * @return array{lead: ?Lead, error: ?string}
     */
    public function handle(
        string $shopId,
        LeadFlow $flow,
        array $given,
        string $pageType,
        string $pageExternalId,
        ?string $ctaId,
        ?string $visitorHash,
        int $questionsAsked,
        ?string $question = null,
    ): array {
        $contact = $this->contactFrom($flow, $given, $shopId);

        if ($contact === null) {
            return ['lead' => null, 'error' => 'no_contact'];
        }

        // What they typed into the shop's own questions, kept apart from how to reach them.
        $answers = [];

        foreach ($flow->asked() as $field) {
            if (! in_array($field['type'], ['phone', 'email'], true) && trim((string) ($given[$field['key']] ?? '')) !== '') {
                $answers[$field['key']] = (string) $given[$field['key']];
            }
        }

        // Left about a question the store did not answer: the team needs the question to answer it.
        if ($question !== null && $question !== '') {
            $answers[LeadFields::QUESTION] = $question;
        }

        $lead = $this->tenant->run($shopId, fn (): Lead => Lead::query()->updateOrCreate(
            [
                'shop_id' => $shopId,
                'contact_hash' => $contact['hash'],
                'page_type' => $pageType,
                'page_external_id' => $pageExternalId,
            ],
            [
                'flow_id' => $flow->id,
                'cta_id' => $ctaId,
                'channel' => $contact['channel'],
                'contact' => $contact['value'],
                'contact_masked' => $contact['masked'],
                'answers' => $answers === [] ? null : $answers,
                'consented_at' => now(),
                'consent_wording' => $flow->consent,
                'quality' => FlowMachine::quality($flow, $given, $questionsAsked),
                'status' => Lead::NEW,
                'visitor_hash' => $visitorHash,
            ],
        ));

        // the store's team is told by mail: a new lead, or the same person's with new details (SendLeadToTeam)
        if ($lead->wasRecentlyCreated || $lead->wasChanged(['contact', 'answers'])) {
            SendLeadToTeam::dispatch($shopId, (string) $lead->id);
        }

        return ['lead' => $lead, 'error' => null];
    }

    /**
     * The way to reach them, from whichever field carries one.
     *
     * @param  array<string, string>  $given
     * @return array{channel: string, value: string, masked: string, hash: string}|null
     */
    private function contactFrom(LeadFlow $flow, array $given, string $shopId): ?array
    {
        foreach ($flow->asked() as $field) {
            if (! in_array($field['type'], ['phone', 'email'], true)) {
                continue;
            }

            $contact = app(ReadsContacts::class)->read((string) ($given[$field['key']] ?? ''), $shopId);

            if ($contact !== null) {
                return $contact;
            }
        }

        return null;
    }
}
