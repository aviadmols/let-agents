<?php

namespace App\Modules\Shoppers\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Shoppers\Mail\VerificationCode;
use App\Modules\Shoppers\Models\ShopperCallback;
use App\Modules\Shoppers\Models\ShopperIdentity;
use App\Modules\Shoppers\Models\ShopperVerification;
use App\Modules\Shoppers\Models\ShopperVisitor;
use App\Modules\Shoppers\Support\Channels;
use App\Modules\Shoppers\Support\Contact;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\Mail;

/**
 * A shopper leaves a phone or an email in the widget.
 *
 * The lead is kept straight away, so the shop has it whatever happens next. When a code can be
 * sent, one is sent: only after they type it back does their browsing follow them to another
 * browser, because until then all we know is that someone typed a number.
 */
final class SignUp
{
    public const CONSENT_VERSION = 'v1';

    public function __construct(private readonly TenantContext $tenant) {}

    /**
     * @return array{status: string, channel?: string, masked?: string, minutes?: int}
     *                                                                                 status: saved, code_sent, invalid_contact, no_consent or too_many
     */
    /**
     * @param  array{question: string, type: string, id: string, name?: string|null}|null  $waitingFor  a question they want
     *                                                                                                  the team to come back to them about
     */
    public function handle(string $shopId, string $visitorHash, string $typed, bool $consent, string $locale, ?array $waitingFor = null): array
    {
        $contact = Contact::parse($typed, $shopId);

        if ($contact === null) {
            return ['status' => 'invalid_contact'];
        }

        if (! $consent) {
            return ['status' => 'no_consent'];
        }

        return $this->tenant->run($shopId, function () use ($shopId, $visitorHash, $contact, $locale, $waitingFor): array {
            $perDay = (int) Settings::get('shoppers.signups_per_visitor_per_day', $shopId);

            if (ShopperVerification::query()->where('visitor_hash', $visitorHash)->where('created_at', '>=', now()->subDay())->count() >= $perDay) {
                return ['status' => 'too_many'];
            }

            $hash = $contact->hash($shopId);

            $identity = ShopperIdentity::query()->firstOrNew(['shop_id' => $shopId, 'contact_hash' => $hash]);
            $identity->fill([
                'channel' => $contact->channel,
                'contact' => $contact->value,
                'contact_masked' => $contact->masked,
                'consented_at' => now(),
                'consent_version' => self::CONSENT_VERSION,
                'last_seen_at' => now(),
            ])->save();

            // This browser is theirs from now on. Typing a different contact moves the link.
            $link = ShopperVisitor::query()->firstOrNew(['shop_id' => $shopId, 'visitor_hash' => $visitorHash]);
            if ($link->identity_id !== $identity->id) {
                $link->verified = false;
            }
            $link->fill(['identity_id' => $identity->id, 'linked_at' => now()])->save();

            // They are not signing up for offers; they are waiting for one answer.
            if ($waitingFor !== null) {
                ShopperCallback::query()->create([
                    'shop_id' => $shopId,
                    'identity_id' => $identity->id,
                    'name' => $waitingFor['name'] ?? null,
                    'question' => mb_substr($waitingFor['question'], 0, 500),
                    'page_type' => $waitingFor['type'],
                    'page_id' => $waitingFor['id'],
                ]);
            }

            // Asked in the search chat to be called back about one question: nothing to type back.
            if (! Channels::canVerify($contact->channel, $shopId) || ($waitingFor['type'] ?? null) === 'search') {
                return ['status' => 'saved', 'channel' => $contact->channel, 'masked' => $contact->masked];
            }

            $minutes = (int) Settings::get('shoppers.code_valid_minutes', $shopId);
            $code = (string) random_int(100000, 999999);

            ShopperVerification::query()->where('visitor_hash', $visitorHash)->delete();
            ShopperVerification::query()->create([
                'shop_id' => $shopId,
                'visitor_hash' => $visitorHash,
                'channel' => $contact->channel,
                'contact_hash' => $hash,
                'contact' => $contact->value,
                'code_hash' => self::codeHash($code),
                'expires_at' => now()->addMinutes($minutes),
                'created_at' => now(),
            ]);

            if ($contact->channel === Contact::EMAIL) {
                $shop = Shop::query()->find($shopId);
                Mail::to($contact->value)->send(new VerificationCode($code, (string) ($shop?->name ?? ''), $minutes, $locale));
            }

            return ['status' => 'code_sent', 'channel' => $contact->channel, 'masked' => $contact->masked, 'minutes' => $minutes];
        });
    }

    public static function codeHash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
