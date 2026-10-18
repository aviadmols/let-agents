<?php

namespace App\Modules\Connections\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Models\User;
use App\Modules\Connections\Actions\StoreAccount;
use App\Modules\Connections\Http\Middleware\FrameInsideWordPress;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The shop's panel inside wp-admin, signed in by the plugin's token.
 *
 *   POST /api/v1/plugin/{site}/panel-login   signed by the plugin (as every plugin call): a one-time
 *                                            address, good for a minute, back to WordPress
 *   GET  /plugin-login/{code}                the frame opens it: the store's own account (StoreAccount)
 *                                            is signed in, and lands in the shop's panel
 *
 * The code is single-use and never leaves the server's cache; the token itself never reaches the browser.
 */
final class PluginPanelController
{
    private const SECONDS = 60;

    private const OVERVIEW = 'filament.merchant.pages.overview';

    public function issue(Request $request, string $site): JsonResponse
    {
        $connection = StoreConnection::forSite($site);

        if ($connection === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }

        $valid = $connection->verifyPluginSignature(
            (string) $request->header('X-LetAgents-Timestamp'),
            $request->method(),
            $request->getRequestUri(),
            (string) $request->getContent(),
            (string) $request->header('X-LetAgents-Signature'),
        );

        if (! $valid) {
            return response()->json(['error' => 'bad_signature'], 401);
        }

        $code = Str::random(48);
        $body = json_decode((string) $request->getContent(), true);
        $locale = is_array($body) && in_array($body['locale'] ?? null, ['he', 'en'], true) ? $body['locale'] : null;
        Cache::put('connections.panel_login.'.$code, ['shop' => $connection->shop_id, 'site' => $site, 'locale' => $locale], self::SECONDS);

        return response()->json(['url' => route('connections.panel_login', ['code' => $code]), 'expires_in' => self::SECONDS])
            ->header('Cache-Control', 'no-store');
    }

    public function open(Request $request, TenantContext $tenant, StoreAccount $accounts, string $code): RedirectResponse|Response
    {
        $ticket = preg_match('/^[A-Za-z0-9]{48}$/', $code) === 1 ? Cache::pull('connections.panel_login.'.$code) : null;
        $shop = is_array($ticket) ? Shop::query()->find($ticket['shop'] ?? null) : null;

        if ($shop === null) {
            // Used, or more than a minute old: WordPress asks for a new one when its page is reloaded.
            return response(view('connections::panel-expired'), 410)->header('Cache-Control', 'no-store');
        }

        $user = $tenant->runUnscoped(fn (): User => $accounts->for($shop, (string) $ticket['site']));

        if ($user->locale === null && $ticket['locale'] !== null) {
            $user->forceFill(['locale' => $ticket['locale']])->save();
        }

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(FrameInsideWordPress::SESSION, $shop->id);

        return redirect()->to(route(self::OVERVIEW, ['tenant' => $shop->slug]));
    }
}
