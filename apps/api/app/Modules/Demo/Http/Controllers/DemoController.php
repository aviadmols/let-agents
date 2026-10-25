<?php

namespace App\Modules\Demo\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Demo\Actions\FinishDemo;
use App\Modules\Demo\Actions\RetryDemo;
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
 *   GET  /demo/{token}/progress the preparation and the scan so far, for the screen to draw, or the problem it hit;
 *                               done, the demo is ready (FinishDemo)
 *   POST /demo/{token}/again    after a problem: again, at the same address or a corrected one (RetryDemo)
 *   GET  /demo/{token}/phone    the demo in its phone: the store's picture and the floating search, framed by the demo
 *   GET  /demo/{token}/shot/{which}  the store's picture (a phone's; a computer's for a demo from before)
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
        $result = $start->handle($request->only(['url', 'email', 'kind', 'matters', 'language', 'consent']), $request->ip(), $locale);

        if (isset($result['error'])) {
            return redirect()->route('demo.form', ['lang' => $locale])->withInput()->with('demo_error', $result['error']);
        }

        return redirect()->route('demo.show', $result['token']);
    }

    public function show(Request $request, string $token): Response
    {
        $site = $this->site($token);
        app()->setLocale(DemoSite::LOCALE);

        return match ($site->status) {
            DemoSite::PENDING => response(view('demo::verify', ['site' => $site, 'result' => session('demo_result')])),
            // a problem is shown on the scan screen, where it happened, with what to do about it
            DemoSite::SCANNING, DemoSite::FAILED => response(view('demo::scan', ['site' => $site, 'steps' => self::steps($site), 'retry' => session('demo_error')])),
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
        $progress = $site->shop !== null && in_array($site->status, [DemoSite::SCANNING, DemoSite::READY], true) ? DemoProgress::of($site->shop, $site->readsPictures()) : null;

        return response()->json([
            'status' => $site->status,
            'error' => $site->status === DemoSite::FAILED ? ($site->error ?? 'other') : null,
            'steps' => self::steps($site),
            // the scan not begun a while after the code: said, so no one waits on a screen that looks still
            'slow' => $site->status === DemoSite::SCANNING && $site->scan_at === null && $site->verified_at?->lt(now()->subMinutes(2)) === true,
            'rows' => $progress['rows'] ?? [],
            'learned' => $progress['learned'] ?? [],
        ])->header('Cache-Control', 'no-store');
    }

    public function again(Request $request, string $token, RetryDemo $retry): RedirectResponse
    {
        $site = $this->site($token);
        $result = $retry->handle($site, is_string($request->input('url')) ? $request->input('url') : null);

        return redirect()->route('demo.show', $site->token)->with('demo_error', $result === 'ok' ? null : $result);
    }

    /**
     * The demo's preparation before its scan, a step at a time (PrepareDemoJob): reading the site, starting the
     * scan. Each waiting, running, done or failed. The home page's picture is taken meanwhile, not shown as a step.
     *
     * @return list<array{key: string, state: string}>
     */
    private static function steps(DemoSite $site): array
    {
        $failed = $site->status === DemoSite::FAILED;
        $state = fn (bool $done, bool $begun): string => match (true) {
            $done => 'done',
            ! $begun => 'waiting',
            $failed => 'failed',
            default => 'running',
        };

        return [
            ['key' => 'read', 'state' => $state($site->read_at !== null, true)],
            ['key' => 'scan', 'state' => $state($site->scan_at !== null, $site->read_at !== null)],
        ];
    }

    /** The demo in its phone: framed by the demo page, ours only. */
    public function phone(string $token): Response
    {
        $site = $this->site($token);
        abort_unless($site->status === DemoSite::READY && $site->live(), 404);
        app()->setLocale(DemoSite::LOCALE);

        return response(view('demo::phone', ['site' => $site, 'siteKey' => $this->siteKey($site)]))
            ->header('Content-Security-Policy', "frame-ancestors 'self';");
    }

    public function shot(string $token, string $which): Response
    {
        $site = $this->site($token);
        $picture = $which === 'mobile' ? $site->screenshot_mobile : $site->screenshot;

        abort_if($picture === null || ! $site->live(), 404);

        return response((string) base64_decode($picture), 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=3600']);
    }

    /** The demo its link names, and the demo's language for all it says (the scan's lines on /progress too). */
    private function site(string $token): DemoSite
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{40}$/', $token) === 1, 404);
        app()->setLocale(DemoSite::LOCALE);

        return DemoSite::query()->where('token', $token)->firstOrFail();
    }

    /** The demo store's storefront key: what the drawer on the demo page asks with. */
    private function siteKey(DemoSite $site): ?string
    {
        return $site->shop_id === null ? null : app(TenantContext::class)->runUnscoped(fn () => StoreConnection::query()->where('shop_id', $site->shop_id)->value('site_key'));
    }

    /**
     * The demo speaks English, every page and email of it, whatever its link or the browser asks (as Aviad asked,
     * 25.10). The search on the demo speaks the store's language, as whoever asked chose it.
     */
    private function speak(Request $request): string
    {
        app()->setLocale(DemoSite::LOCALE);

        return DemoSite::LOCALE;
    }
}
