<?php

namespace App\Modules\Demo\Http\Controllers;

use App\Core\Localization\Locales;
use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Demo\Actions\FinishDemo;
use App\Modules\Demo\Actions\StartDemo;
use App\Modules\Demo\Actions\VerifyDemo;
use App\Modules\Demo\Models\DemoSite;
use App\Modules\Demo\Support\DemoProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * The demo, as whoever asked for it goes through it, in Lets' own look (lets.co.il):
 *
 *   GET  /demo                  the store's address, their email, what the site is and what matters in its search
 *   POST /demo                  checked (StartDemo): a code goes to the email
 *   GET  /demo/{token}          by where it stands: the code to type, the scan as a real store's first run shows it,
 *                               the demo itself (the store's picture with Lets' floating search on it), or its end
 *   POST /demo/{token}/verify   the code (VerifyDemo): the demo store is built and its scan starts
 *   POST /demo/{token}/code     a new code
 *   GET  /demo/{token}/progress the scan so far, for the screen to draw; done, the demo is ready (FinishDemo)
 *   GET  /demo/{token}/shot/{which}  the store's picture, for a computer or a phone
 */
final class DemoController extends Controller
{
    public function form(Request $request): Response
    {
        return response(view('demo::start', ['locale' => $this->speak($request), 'error' => session('demo_error'), 'old' => session()->getOldInput()]));
    }

    public function start(Request $request, StartDemo $start): RedirectResponse
    {
        $locale = $this->speak($request);
        $result = $start->handle($request->only(['url', 'email', 'kind', 'matters', 'consent']), $request->ip(), $locale);

        if (isset($result['error'])) {
            return redirect()->route('demo.form', ['lang' => $locale])->withInput()->with('demo_error', $result['error']);
        }

        return redirect()->route('demo.show', $result['token']);
    }

    public function show(Request $request, string $token): Response
    {
        $site = $this->site($token);
        app()->setLocale($site->locale);

        return match ($site->status) {
            DemoSite::PENDING => response(view('demo::verify', ['site' => $site, 'result' => session('demo_result')])),
            DemoSite::SCANNING => response(view('demo::scan', ['site' => $site])),
            DemoSite::READY => $site->live() ? response(view('demo::view', ['site' => $site, 'siteKey' => $this->siteKey($site)])) : response(view('demo::ended', ['site' => $site])),
            default => response(view('demo::ended', ['site' => $site])),
        };
    }

    public function verify(Request $request, string $token, VerifyDemo $verify): RedirectResponse
    {
        $site = $this->site($token);
        $result = $verify->handle($site, (string) $request->input('code', ''));

        return redirect()->route('demo.show', $site->token)->with('demo_result', $result === 'ok' || $result === 'done' ? null : $result);
    }

    public function code(string $token): RedirectResponse
    {
        $site = $this->site($token);

        if ($site->status === DemoSite::PENDING) {
            StartDemo::newCode($site);
        }

        return redirect()->route('demo.show', $site->token)->with('demo_result', 'sent');
    }

    public function progress(string $token, FinishDemo $finish): JsonResponse
    {
        $site = $finish->handle($this->site($token));
        $progress = $site->shop !== null && in_array($site->status, [DemoSite::SCANNING, DemoSite::READY], true) ? DemoProgress::of($site->shop) : null;

        return response()->json([
            'status' => $site->status,
            'rows' => $progress['rows'] ?? [],
            'learned' => $progress['learned'] ?? [],
        ])->header('Cache-Control', 'no-store');
    }

    public function shot(string $token, string $which): Response
    {
        $site = $this->site($token);
        $picture = $which === 'mobile' ? $site->screenshot_mobile : $site->screenshot;

        abort_if($picture === null || ! $site->live(), 404);

        return response((string) base64_decode($picture), 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=3600']);
    }

    private function site(string $token): DemoSite
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);

        return DemoSite::query()->where('token', $token)->firstOrFail();
    }

    /** The demo store's storefront key: what the drawer on the demo page asks with. */
    private function siteKey(DemoSite $site): ?string
    {
        return $site->shop_id === null ? null : app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->where('shop_id', $site->shop_id)->value('site_key'));
    }

    /**
     * The page's language: as its link asks (?lang=he from lets.co.il's Hebrew pages), else English (as Aviad asked,
     * 25.10). Never the browser's. The store's own language is read from its site (PrepareDemoJob), whatever this is.
     */
    private function speak(Request $request): string
    {
        $asked = $request->query('lang', $request->input('lang'));
        $locale = Locales::negotiate(is_string($asked) ? $asked : null) ?? 'en';
        app()->setLocale($locale);

        return $locale;
    }
}
