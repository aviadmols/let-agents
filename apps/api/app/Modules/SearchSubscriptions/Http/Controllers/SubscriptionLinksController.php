<?php

namespace App\Modules\SearchSubscriptions\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Modules\SearchSubscriptions\Actions\ConfirmSubscription;
use App\Modules\SearchSubscriptions\Actions\KeepSubscription;
use App\Modules\SearchSubscriptions\Actions\StopSubscriptions;
use App\Modules\SearchSubscriptions\Models\SearchSubscription;
use App\Modules\SearchSubscriptions\Models\SearchSubscriptionSend;
use App\Modules\SearchSubscriptions\Support\Subscribers;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The links of Search Subscriptions' emails: each signed (routes/web.php, middleware "signed"), so one tap works with no
 * login and no link can be made up. In English, as the emails are.
 */
final class SubscriptionLinksController
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function confirm(string $subscription, ConfirmSubscription $confirm): View
    {
        $s = $this->find($subscription);
        $ok = $s !== null && $this->tenant->run($s->shop_id, fn (): bool => $confirm->handle($s));

        return $this->page($s, $ok ? 'You’re subscribed.' : 'This link has ended.',
            $ok ? 'We’ll email you once a week at most, only when new “'.Subscribers::cut($s->query, 40).'” items arrive.' : 'Subscribe again from the store’s search.');
    }

    public function stop(string $subscription, StopSubscriptions $stop): View
    {
        $s = $this->find($subscription);
        if ($s !== null) {
            $stop->one($s);
        }

        return $this->page($s, 'Stopped.', $s === null ? 'There is nothing more to stop.' : 'You won’t get emails for “'.Subscribers::cut($s->query, 40).'” anymore.');
    }

    public function stopAll(string $shop, string $address, StopSubscriptions $stop): View
    {
        $stop->all($shop, $address);

        return $this->page(null, 'Unsubscribed from all.', 'You won’t get any more of these emails from this store.', $shop);
    }

    /** RFC 8058: a mail client's own unsubscribe button posts here, with no page shown. */
    public function oneClick(string $shop, string $address, StopSubscriptions $stop): Response
    {
        $stop->all($shop, $address);

        return response('', 200);
    }

    public function keep(string $subscription, KeepSubscription $keep): View
    {
        $s = $this->find($subscription);
        $ok = $s !== null && $this->tenant->run($s->shop_id, fn (): bool => $keep->handle($s));

        return $this->page($s, $ok ? 'Kept.' : 'This search has ended.', $ok ? 'We’ll keep emailing you new “'.Subscribers::cut($s->query, 40).'” items.' : 'Subscribe again from the store’s search.');
    }

    public function manage(string $shop, string $address): View
    {
        $searches = $this->tenant->run($shop, fn () => SearchSubscription::query()->where('email_hash', $address)
            ->where('status', '!=', SearchSubscription::STOPPED)->orderBy('created_at')->get());

        return view('search_subscriptions::pages.manage', [
            'store' => (string) (Shop::query()->whereKey($shop)->value('name') ?? ''),
            'searches' => $searches->map(fn (SearchSubscription $s): array => ['query' => Subscribers::cut($s->query, 80), 'stop' => Subscribers::stopUrl($s),
                'pending' => $s->status === SearchSubscription::PENDING, 'ends' => $s->expires_at?->format('j M')])->all(),
            'stopAll' => Subscribers::stopAllUrl($shop, $address),
        ]);
    }

    /** The 1x1 picture of an email: it was opened (as far as a mail client shows pictures). */
    public function opened(string $send): Response
    {
        $this->tenant->runUnscoped(fn () => SearchSubscriptionSend::query()->whereKey($send)->whereNull('opened_at')->update(['opened_at' => now()]));

        return response(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 200, ['Content-Type' => 'image/gif', 'Cache-Control' => 'no-store']);
    }

    /** A link of an email to the store: counted, then on to where the email said (the address is in the signature). */
    public function clicked(string $send): RedirectResponse
    {
        $to = (string) request()->query('to', '');
        $this->tenant->runUnscoped(fn () => SearchSubscriptionSend::query()->whereKey($send)->update(['clicked_at' => now()]
            + (SearchSubscriptionSend::query()->whereKey($send)->value('opened_at') === null ? ['opened_at' => now()] : [])));

        return redirect()->away(preg_match('#^https?://#i', $to) ? $to : '/');
    }

    private function find(string $id): ?SearchSubscription
    {
        return $this->tenant->runUnscoped(fn () => SearchSubscription::query()->find($id));
    }

    private function page(?SearchSubscription $s, string $title, string $line, ?string $shopId = null): View
    {
        $shopId ??= $s?->shop_id;

        return view('search_subscriptions::pages.done', [
            'store' => $shopId === null ? '' : (string) (Shop::query()->whereKey($shopId)->value('name') ?? ''),
            'title' => $title, 'line' => $line,
            'manage' => $s === null ? null : Subscribers::manageUrl($s->shop_id, $s->email_hash),
        ]);
    }
}
