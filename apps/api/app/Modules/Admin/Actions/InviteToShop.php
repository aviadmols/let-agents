<?php

namespace App\Modules\Admin\Actions;

use App\Core\Facades\Settings;
use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Enums\ShopRole;
use App\Modules\Admin\Mail\ShopInvitationMail;
use App\Modules\Admin\Models\ShopInvitation;
use App\Modules\Admin\Models\User;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Invites someone to the shop's panel, or sends their invitation again: a new token every time (the
 * older link stops working), a signed link that lasts admin.invitation_days, and the email in the
 * language their panel will have. The person sets their own password when they open it. Not past
 * admin.max_team_members (the shop's plan may hold it lower): its users and the invitations still
 * waiting count.
 */
final class InviteToShop
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function handle(Shop $shop, ?User $by, string $email, string $name, ShopRole $role, string $locale): ShopInvitation
    {
        $email = Str::lower(trim($email));

        if (! $this->hasRoom($shop, $email)) {
            throw new RuntimeException('The shop\'s panel is full: admin.max_team_members.');
        }

        $invitation = $this->tenant->run((string) $shop->id, fn (): ShopInvitation => ShopInvitation::query()->firstOrNew(['email' => $email]));
        $invitation->forceFill([
            'shop_id' => $shop->id,
            'name' => trim($name) === '' ? null : mb_substr(trim($name), 0, 120),
            'role' => $role,
            'locale' => $locale,
            'invited_by' => $by?->id,
        ]);

        return $this->send($invitation, $shop, $by);
    }

    /** Room for one more in the shop's panel; an invitation to $email already waiting is that one, not another. */
    public function hasRoom(Shop $shop, ?string $email = null): bool
    {
        $users = DB::table('shop_user')->where('shop_id', $shop->id)->count();
        $waiting = DB::table('shop_invitations')->where('shop_id', $shop->id)->whereNull('accepted_at')->where('expires_at', '>', now())
            ->when($email !== null, fn ($q) => $q->where('email', '!=', Str::lower(trim((string) $email))))->count();

        return $users + $waiting < (int) Settings::get('admin.max_team_members', $shop->id);
    }

    /** The same invitation again, with a new link. */
    public function resend(ShopInvitation $invitation, ?User $by): ShopInvitation
    {
        /** @var Shop $shop */
        $shop = Shop::query()->findOrFail($invitation->shop_id);

        return $this->send($invitation, $shop, $by);
    }

    private function send(ShopInvitation $invitation, Shop $shop, ?User $by): ShopInvitation
    {
        $token = Str::random(48);
        $expires = now()->addDays((int) Settings::get('admin.invitation_days'));

        $invitation->forceFill([
            'token_hash' => ShopInvitation::hashOf($token),
            'sent_at' => now(),
            'expires_at' => $expires,
            'accepted_at' => null,
        ]);
        $this->tenant->run((string) $shop->id, fn () => $invitation->save());

        $link = URL::temporarySignedRoute('admin.invitation', $expires, ['invitation' => $invitation->id, 'token' => $token]);

        Mail::to($invitation->email)
            ->locale($invitation->locale)
            ->send(new ShopInvitationMail($shop->name, $by?->name ?: null, $invitation->role, $link, $expires->copy()));

        return $invitation;
    }
}
