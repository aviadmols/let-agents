<?php

namespace App\Modules\Ai\Support;

use App\Modules\Admin\Models\User;
use App\Modules\Ai\Enums\ProviderStatus;
use App\Modules\Ai\Filament\Operator\Resources\AiProviders\AiProviderResource;
use App\Modules\Ai\Models\AiProvider;
use Filament\Facades\Filament;
use Illuminate\Support\HtmlString;

/**
 * A red bar at the top of every operator screen while a model provider's key does not work (refused,
 * not allowed, out of credit, as a live call or a check found): which provider, why, since when, what
 * shoppers get meanwhile, and the way to the screen where the key is changed. Gone once a key passes
 * its check. Never in a shop's panel: a shop manager does not see the providers.
 */
final class KeyAlert
{
    public function render(): HtmlString
    {
        if (Filament::getCurrentPanel()?->getId() !== User::OPERATOR_PANEL) {
            return new HtmlString('');
        }

        $failed = AiProvider::query()->where('status', ProviderStatus::Failed)->orderBy('provider')->get();

        if ($failed->isEmpty()) {
            return new HtmlString('');
        }

        return new HtmlString(view('ai::key-alert', [
            'providers' => $failed->map(fn (AiProvider $p): array => [
                'name' => $p->provider->label(),
                'reason' => __('ai::providers.errors.'.($p->last_error_code ?? 'provider_error')),
                'since' => $p->last_checked_at?->timezone('Asia/Jerusalem')->format('d/m H:i'),
            ])->all(),
            'link' => AiProviderResource::getUrl('index', panel: User::OPERATOR_PANEL),
        ])->render());
    }
}
