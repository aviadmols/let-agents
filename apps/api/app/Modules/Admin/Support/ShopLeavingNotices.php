<?php

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Filament\Merchant\Pages\PrivacySettings;
use App\Modules\Admin\Mail\ShopLeavingMail;
use App\Modules\Admin\Mail\TeamNoticeMail;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Who is told when a shop's plan ends or its deletion is asked: its owners, in their panel's language, and the
 * operators. An email that fails is written to the log; what the owner did stands.
 */
final class ShopLeavingNotices
{
    public static function owners(Shop $shop, string $kind, ?string $askedBy): void
    {
        $link = PrivacySettings::getUrl(tenant: $shop);

        foreach ($shop->belongsToMany(User::class, 'shop_user')->wherePivot('role', ShopRole::Owner->value)->get() as $owner) {
            self::send($owner, new ShopLeavingMail($kind, (string) $shop->name, $askedBy, $shop->deletion_due_at, $link));
        }
    }

    public static function team(Shop $shop, string $kind, ?string $by, bool $billedByHand, ?string $plan = null): void
    {
        foreach (User::query()->where('is_operator', true)->get() as $operator) {
            self::send($operator, new TeamNoticeMail($kind, (string) $shop->name, (string) $shop->domain, $by, $billedByHand, $shop->deletion_due_at, $plan, $shop->planRunsUntil()));
        }
    }

    private static function send(User $to, ShopLeavingMail|TeamNoticeMail $mail): void
    {
        try {
            Mail::to($to->email)->locale($to->preferredLocale() ?? app()->getLocale())->send($mail);
        } catch (Throwable $e) {
            Log::warning('A shop-leaving email was not sent', ['to' => $to->id, 'kind' => $mail->kind, 'error' => $e->getMessage()]);
        }
    }
}
