<?php

namespace App\Modules\SearchSubscriptions\Support;

use App\Modules\Catalog\Models\CatalogProduct;
use App\Modules\SearchSubscriptions\Actions\SendWeekly;
use App\Modules\SearchSubscriptions\Mail\WeeklyNewArrivalsMail;
use App\Modules\SearchSubscriptions\Models\SearchSubscription;
use App\Modules\SearchSubscriptions\Models\SearchSubscriptionProduct;
use App\Modules\SearchSubscriptions\Models\SearchSubscriptionSend;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Transport\ResendTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\Mime\Address as MimeAddress;
use Throwable;

/**
 * Mails weekly emails SendWeekly planned (a send row and the products it carries), up to SendWeekly::BATCH at a time,
 * inside the store's tenant. Each email is built from what it planned, as it stands now: a search stopped since is left
 * out, and an address with nothing left gets nothing (its send is taken back).
 *
 * Through Resend, the emails go in one batch request (100 at most, one request instead of a hundred against its rate
 * limit), with an idempotency key, so a retried job is not mailed twice by Resend either; anything else (a log mailer,
 * tests) sends them one by one. A send is marked mailed the moment the service took it, and a retried delivery skips it.
 */
final class WeeklyEmails
{
    private const BATCH_URL = 'https://api.resend.com/emails/batch';

    /** Whether the emails go through Resend's batch: the app's mailer is Resend and has a key. */
    public static function batched(): bool
    {
        $mail = app('mail.manager');
        if (! $mail instanceof MailManager || (string) config('services.resend.key') === '') {
            return false;
        }
        try {
            return $mail->mailer()->getSymfonyTransport() instanceof ResendTransport;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<string>  $sendIds
     * @return int the emails mailed
     *
     * @throws MailServiceBusy when the service asks to wait
     */
    public function deliver(string $shopId, array $sendIds): int
    {
        $sends = SearchSubscriptionSend::query()->whereIn('id', $sendIds)->where('kind', 'weekly')->whereNull('mailed_at')->get(['id', 'email_hash']);
        if ($sends->isEmpty()) {
            return 0;
        }
        $shop = Shop::query()->find($shopId);
        $rows = SearchSubscriptionProduct::query()->toBase()->whereIn('send_id', $sends->pluck('id')->all())->whereNotNull('subscription_id')
            ->orderBy('id')->get(['send_id', 'subscription_id', 'product_id']);
        $bySend = [];
        foreach ($rows as $row) {
            $bySend[(string) $row->send_id][(string) $row->subscription_id][] = (string) $row->product_id;
        }
        $products = CatalogProduct::query()->whereIn('id', $rows->pluck('product_id')->unique()->values()->all())
            ->get(['id', 'title', 'price', 'currency', 'image_url', 'url'])->keyBy('id');
        // each address's searches still active, first confirmed first: the order the email lists them in
        $byAddress = SearchSubscription::query()->where('status', SearchSubscription::ACTIVE)->whereIn('email_hash', $sends->pluck('email_hash')->unique()->values()->all())
            ->orderBy('confirmed_at')->orderBy('id')->get(['id', 'email', 'email_hash', 'query'])->groupBy('email_hash');

        $emails = [];
        $empty = [];
        foreach ($sends as $send) {
            $searches = [];
            $mine = $byAddress->get($send->email_hash, collect());
            foreach ($mine as $subscription) {
                $picked = array_values(array_filter(array_map(fn (string $id): ?CatalogProduct => $products->get($id), $bySend[$send->id][$subscription->id] ?? [])));
                if ($picked === []) {
                    continue;
                }
                $searches[] = [
                    'query' => Subscribers::cut((string) $subscription->query, 80),
                    'stop' => Subscribers::stopUrl($subscription),
                    'products' => array_map(fn (CatalogProduct $p): array => [
                        'title' => Subscribers::cut((string) $p->title, 60),
                        'price' => SendWeekly::price($p),
                        'image' => (string) ($p->image_url ?? ''),
                        'url' => Subscribers::clickUrl($send->id, Subscribers::tagged((string) $p->url)),
                    ], $picked),
                ];
            }
            if ($searches === []) {
                $empty[] = $send->id;

                continue;
            }
            $emails[$send->id] = [(string) $mine->first()->email, new WeeklyNewArrivalsMail($shopId, (string) ($shop->name ?? ''), $send->id, $send->email_hash,
                $searches, $shop?->domain ? 'https://'.$shop->domain : '')];
        }
        self::takeBack($empty);
        if ($emails === []) {
            return 0;
        }

        [$mailed, $failed] = self::batched() ? $this->viaBatch($emails) : $this->oneByOne($emails);
        if ($mailed !== []) {
            SearchSubscriptionSend::query()->whereIn('id', $mailed)->update(['mailed_at' => now()]);
        }
        self::takeBack($failed);

        return count($mailed);
    }

    /**
     * Sends that never went: gone, with the products they would have carried, so the address may get them next time.
     *
     * @param  list<string>  $sendIds
     */
    public static function takeBack(array $sendIds): void
    {
        if ($sendIds === []) {
            return;
        }
        SearchSubscriptionProduct::query()->whereIn('send_id', $sendIds)->delete();
        SearchSubscriptionSend::query()->whereIn('id', $sendIds)->whereNull('mailed_at')->delete();
    }

    /**
     * @param  array<string, array{0: string, 1: WeeklyNewArrivalsMail}>  $emails
     * @return array{0: list<string>, 1: list<string>} mailed, failed
     */
    private function oneByOne(array $emails): array
    {
        $mailed = [];
        $failed = [];
        foreach ($emails as $sendId => [$to, $mail]) {
            try {
                Mail::to($to)->send($mail);
                $mailed[] = (string) $sendId;
            } catch (Throwable $e) {
                $failed[] = (string) $sendId;
                Log::warning('search subscriptions: a weekly email failed', ['send' => $sendId, 'error' => $e->getMessage()]);
            }
        }

        return [$mailed, $failed];
    }

    /**
     * One request to Resend's batch for all of them. Permissive: an address it refuses fails alone, the rest go.
     *
     * @param  array<string, array{0: string, 1: WeeklyNewArrivalsMail}>  $emails
     * @return array{0: list<string>, 1: list<string>} mailed, failed
     */
    private function viaBatch(array $emails): array
    {
        $ids = array_map('strval', array_keys($emails));
        $payload = [];
        foreach ($emails as [$to, $mail]) {
            $envelope = $mail->envelope();
            $payload[] = array_filter([
                'from' => self::address($envelope->from),
                'to' => [$to],
                'subject' => (string) $envelope->subject,
                'html' => $mail->render(),
                'reply_to' => array_map(fn ($a): string => self::address($a), $envelope->replyTo),
                'headers' => $mail->headers()->text,
            ], fn ($v): bool => $v !== [] && $v !== '');
        }

        $sorted = $ids;
        sort($sorted, SORT_STRING);
        $response = Http::withToken((string) config('services.resend.key'))->acceptJson()->timeout(60)
            ->withHeaders(['Idempotency-Key' => 'search-subscriptions-weekly-'.sha1(implode(',', $sorted)), 'x-batch-validation' => 'permissive'])
            ->post(self::BATCH_URL, $payload);

        $error = (string) $response->json('name', '');
        if ($response->status() === 429 || ($response->status() === 409 && $error === 'concurrent_idempotent_requests')) {
            throw new MailServiceBusy(max(1, (int) $response->header('retry-after')));
        }
        if ($response->status() === 409 && $error === 'invalid_idempotent_request') {
            // these very emails were taken by an earlier request with this key (a retry after a lost answer)
            return [$ids, []];
        }
        if (! $response->successful()) {
            throw new RuntimeException('Resend batch refused: '.$response->status().' '.mb_substr((string) $response->body(), 0, 300));
        }

        $failed = [];
        foreach ((array) $response->json('errors', []) as $refused) {
            $index = is_array($refused) ? ($refused['index'] ?? null) : null;
            if (is_int($index) && isset($ids[$index])) {
                $failed[] = $ids[$index];
                Log::warning('search subscriptions: a weekly email was refused', ['send' => $ids[$index], 'error' => (string) ($refused['message'] ?? '')]);
            }
        }

        return [array_values(array_diff($ids, $failed)), $failed];
    }

    private static function address(Address|string|null $address): string
    {
        if ($address === null) {
            return '';
        }
        if (is_string($address)) {
            return $address;
        }

        return (new MimeAddress($address->address, (string) $address->name))->toString();
    }
}
