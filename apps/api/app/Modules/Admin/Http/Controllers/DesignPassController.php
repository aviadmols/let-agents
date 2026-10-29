<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Filament\Merchant\Pages\ShopOverview;
use App\Modules\Admin\Models\DesignPass;
use App\Modules\Admin\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A designer's link (CreateDesignPass): signs in as the shop's design account and opens its panel, for as long as the
 * pass is open (EndDesignPass closes the session when it is not). And the stylesheet the panel loads from the
 * designer's own computer instead of its own (PanelTheme::merchant), set or cleared from the design bar.
 */
final class DesignPassController
{
    public function enter(Request $request, string $token): View|RedirectResponse
    {
        $pass = DesignPass::query()->where('token_hash', DesignPass::hash($token))->first();

        if ($pass === null || ! $pass->open() || $pass->shop === null || $pass->user === null) {
            return view('admin::design.closed');
        }

        Auth::login($pass->user);
        $request->session()->regenerate();
        $request->session()->put(DesignPass::SESSION, $pass->id);
        $pass->forceFill(['last_used_at' => now()])->save();

        return redirect()->to(ShopOverview::getUrl(panel: User::MERCHANT_PANEL, tenant: $pass->shop));
    }

    /** The stylesheet from the designer's computer: set (localhost only), or cleared. Back to the screen it was set on. */
    public function css(Request $request): RedirectResponse
    {
        if ($request->session()->get(DesignPass::SESSION) === null) {
            abort(403);
        }

        $url = DesignPass::localCss((string) $request->input('url', ''));
        $url === null ? $request->session()->forget(DesignPass::SESSION_CSS) : $request->session()->put(DesignPass::SESSION_CSS, $url);

        return redirect()->back();
    }

    public function closed(): View
    {
        return view('admin::design.closed');
    }
}
