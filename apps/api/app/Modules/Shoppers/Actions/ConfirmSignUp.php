<?php

namespace App\Modules\Shoppers\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Shoppers\Models\ShopperIdentity;
use App\Modules\Shoppers\Models\ShopperVerification;
use App\Modules\Shoppers\Models\ShopperVisitor;

/**
 * The shopper types the code back. Only now does this browser count as proven, and only proven
 * browsers share one shopper's browsing.
 */
final class ConfirmSignUp
{
    public function __construct(private readonly TenantContext $tenant) {}

    /** @return array{status: string, masked?: string} status: verified, wrong_code, expired or too_many */
    public function handle(string $shopId, string $visitorHash, string $code): array
    {
        return $this->tenant->run($shopId, function () use ($shopId, $visitorHash, $code): array {
            $pending = ShopperVerification::query()->where('visitor_hash', $visitorHash)->latest('id')->first();

            if ($pending === null || $pending->expires_at->isPast()) {
                return ['status' => 'expired'];
            }

            // a try is taken before the code is compared, in one statement: guesses sent at once each take their own
            // and none gets past the limit (1.11)
            $took = ShopperVerification::query()->whereKey($pending->getKey())
                ->where('attempts', '<', (int) Settings::get('shoppers.code_attempts', $shopId))->increment('attempts');

            if ($took === 0) {
                return ['status' => 'too_many'];
            }

            if (! hash_equals($pending->code_hash, SignUp::codeHash(trim($code)))) {
                return ['status' => 'wrong_code'];
            }

            $identity = ShopperIdentity::query()->where('contact_hash', $pending->contact_hash)->first();

            if ($identity === null) {
                return ['status' => 'expired'];
            }

            $identity->forceFill(['verified_at' => $identity->verified_at ?? now(), 'last_seen_at' => now()])->save();

            ShopperVisitor::query()->updateOrCreate(
                ['shop_id' => $shopId, 'visitor_hash' => $visitorHash],
                ['identity_id' => $identity->id, 'verified' => true, 'linked_at' => now()],
            );

            ShopperVerification::query()->where('visitor_hash', $visitorHash)->delete();

            return ['status' => 'verified', 'masked' => $identity->contact_masked];
        });
    }
}
