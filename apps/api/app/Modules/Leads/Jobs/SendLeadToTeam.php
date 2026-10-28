<?php

namespace App\Modules\Leads\Jobs;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Models\User;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Leads\Mail\LeadReceivedMail;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadFlow;
use App\Modules\Leads\Support\LeadFields;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;

/**
 * A new lead, mailed to the store's team: its owners and members (a viewer only looks), each in their panel's
 * language, and the address the shop adds (leads.notify_email). The details are the store's own: somebody who
 * agreed to be contacted by it. Off where the shop turns it off (leads.email_team).
 */
final class SendLeadToTeam implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** The panel's screen of what happened, opened on the leads. */
    private const TIMELINE_ROUTE = 'filament.merchant.pages.timeline';

    public function __construct(public readonly string $shopId, public readonly string $leadId) {}

    public function handle(TenantContext $tenant): void
    {
        $shop = Shop::query()->find($this->shopId);
        if ($shop === null || ! Settings::get('leads.email_team', $this->shopId)) {
            return;
        }

        $tenant->run($this->shopId, function () use ($shop): void {
            $lead = Lead::query()->find($this->leadId);
            if ($lead === null) {
                return;
            }

            $to = $shop->belongsToMany(User::class, 'shop_user')->wherePivotIn('role', [ShopRole::Owner->value, ShopRole::Member->value])->get()
                ->mapWithKeys(fn (User $u): array => [mb_strtolower((string) $u->email) => $u->preferredLocale()]);
            $extra = trim((string) Settings::get('leads.notify_email', $this->shopId));
            if ($extra !== '' && filter_var($extra, FILTER_VALIDATE_EMAIL) && ! $to->has(mb_strtolower($extra))) {
                $to->put(mb_strtolower($extra), (string) ($shop->content_locale ?: 'he'));
            }

            foreach ($to as $email => $locale) {
                $locale = in_array($locale, ['he', 'en'], true) ? $locale : 'he';
                Mail::to($email)->locale($locale)->send($this->mail($shop, $lead, $locale));
            }
        });
    }

    private function mail(Shop $shop, Lead $lead, string $locale): LeadReceivedMail
    {
        $answers = (array) ($lead->answers ?? []);
        $labels = [];
        foreach ((LeadFlow::query()->find($lead->flow_id)?->asked() ?? []) as $field) {
            $labels[(string) $field['key']] = (string) ($field['label'] ?? $field['key']);
        }
        $question = $answers[LeadFields::QUESTION] ?? null;
        unset($answers[LeadFields::QUESTION]);

        $page = match ($lead->page_type) {
            'product' => CatalogProduct::query()->where('external_id', $lead->page_external_id)->first(['title', 'url']),
            'content' => CatalogContent::query()->where('external_id', $lead->page_external_id)->first(['title', 'url']),
            default => null,
        };

        return new LeadReceivedMail(
            shopName: (string) $shop->name,
            channel: $lead->channel === 'email' ? 'email' : 'phone',
            contact: (string) $lead->contact,
            question: $question === null ? null : (string) $question,
            answers: array_values(array_map(fn (string $key, $value): array => ['label' => $labels[$key] ?? $key, 'value' => (string) $value], array_keys($answers), array_values($answers))),
            pageTitle: $page?->title,
            pageUrl: $page?->url,
            at: $lead->created_at->copy()->timezone((string) config('app.display_timezone', 'Asia/Jerusalem'))->locale($locale)->translatedFormat('j.n.Y H:i'),
            panelUrl: Route::has(self::TIMELINE_ROUTE) ? route(self::TIMELINE_ROUTE, ['tenant' => $shop->slug, 'kind' => 'lead']) : null,
        );
    }
}
