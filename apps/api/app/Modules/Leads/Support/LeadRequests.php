<?php

namespace App\Modules\Leads\Support;

use App\Modules\Admin\Contracts\ReplyLink;
use App\Modules\Admin\Contracts\ShopperRequests;
use App\Modules\Catalog\Models\CatalogContent;
use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\Leads\Models\Lead;
use App\Modules\Tenancy\Models\Shop;
use Carbon\CarbonInterface;

/**
 * The lead forms not handled yet, on the Shoppers screen's "Waiting for you": what the shopper asked (or, with no
 * question, what they filled in), and their name when the form asked for one. Opening the reply is looking at the
 * contact, so it is recorded on the lead (seen_by), as the leads list records it. Runs inside the shop's TenantContext.
 */
final class LeadRequests implements ShopperRequests
{
    private const PREFIX = 'lead:';

    public function waiting(Shop $shop, int $limit): array
    {
        $leads = Lead::query()->where('status', Lead::NEW)->latest('created_at')->orderByDesc('id')->limit($limit)->get();
        $pages = self::pages($leads);

        return $leads
            ->map(fn (Lead $lead): array => [
                'key' => self::PREFIX.$lead->id,
                'name' => self::name($lead),
                'channel' => $lead->channel === 'email' ? 'email' : 'phone',
                'question' => self::said($lead),
                'at' => $lead->created_at,
                'visitor' => is_string($lead->visitor_hash) && $lead->visitor_hash !== '' ? $lead->visitor_hash : null,
                // the page they asked on: what the question is about
                'page' => $pages[$lead->page_type.'|'.$lead->page_external_id] ?? null,
            ])->all();
    }

    /**
     * The pages these were asked on, by their type and id: a product with its title, link and picture, an article with
     * its title and link; none for the search box, or a page no longer in the catalogue.
     *
     * @param  iterable<object>  $rows
     * @return array<string, array{kind: string, title: string, url: string|null, image: string|null}> "type|id" => page
     */
    private static function pages(iterable $rows): array
    {
        $ids = ['product' => [], 'content' => []];
        foreach ($rows as $row) {
            $type = (string) $row->page_type;
            if (isset($ids[$type]) && (string) $row->page_external_id !== '') {
                $ids[$type][] = (string) $row->page_external_id;
            }
        }

        $pages = [];
        if ($ids['product'] !== []) {
            foreach (CatalogProduct::query()->whereIn('external_id', array_unique($ids['product']))->get(['external_id', 'title', 'url', 'image_url']) as $p) {
                $pages['product|'.$p->external_id] = ['kind' => 'product', 'title' => (string) $p->title, 'url' => $p->url, 'image' => $p->image_url];
            }
        }
        if ($ids['content'] !== []) {
            foreach (CatalogContent::query()->whereIn('external_id', array_unique($ids['content']))->get(['external_id', 'title', 'url']) as $c) {
                $pages['content|'.$c->external_id] = ['kind' => 'content', 'title' => (string) $c->title, 'url' => $c->url, 'image' => null];
            }
        }

        return $pages;
    }

    public function replyUrl(Shop $shop, string $key, ?int $userId): ?string
    {
        $lead = $this->lead($key);
        if ($lead === null) {
            return null;
        }
        $lead->forceFill(['seen_by' => $userId, 'seen_at' => $lead->seen_at ?? now()])->save();

        return ReplyLink::make($lead->channel === 'email' ? 'email' : 'phone', (string) $lead->contact, self::name($lead), self::said($lead));
    }

    public function answered(Shop $shop, string $key, ?int $userId): bool
    {
        $lead = $this->lead($key);

        return $lead !== null && $lead->forceFill(['status' => Lead::HANDLED])->save();
    }

    public function signUps(Shop $shop, CarbonInterface $since, CarbonInterface $until): int
    {
        return 0;
    }

    private function lead(string $key): ?Lead
    {
        return str_starts_with($key, self::PREFIX)
            ? Lead::query()->whereKey(substr($key, strlen(self::PREFIX)))->where('status', Lead::NEW)->first()
            : null;
    }

    private static function name(Lead $lead): ?string
    {
        $name = trim((string) (((array) $lead->answers)['name'] ?? ''));

        return $name === '' ? null : $name;
    }

    /** The question they asked, or what they filled in when the form asked none. */
    private static function said(Lead $lead): string
    {
        $answers = (array) $lead->answers;
        $question = trim((string) ($answers[LeadFields::QUESTION] ?? ''));
        if ($question !== '') {
            return $question;
        }
        unset($answers['name']);

        return implode(' · ', array_values(array_filter(array_map(fn ($v): string => is_scalar($v) ? trim((string) $v) : '', $answers), fn (string $v): bool => $v !== '')));
    }
}
