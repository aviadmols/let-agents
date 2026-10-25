<?php

namespace App\Modules\Demo\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Modules\Catalog\Models\CatalogProduct;
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
 *                               the demo itself (a phone with Lets' floating search in it), or its end
 *   POST /demo/{token}/verify   the code (VerifyDemo): the demo store is built and its scan starts
 *   POST /demo/{token}/code     a new code
 *   GET  /demo/{token}/progress the preparation and the scan so far, for the screen to draw, or the problem it hit;
 *                               done, the demo is ready (FinishDemo)
 *   POST /demo/{token}/again    after a problem: again, at the same address or a corrected one (RetryDemo)
 *   GET  /demo/{token}/phone    the demo in its phone: what it is and the floating search, framed by the demo page
 *   GET  /demo/{token}/options/{id}  a product's choices for the demo's cart sheet (its cart adds to no real store)
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
        $steps = self::steps($site);

        // what is at work now, for the screen's live line: a step of the preparation, else the scan's first part running
        $now = collect($steps)->firstWhere('state', 'running')['key'] ?? collect($progress['rows'] ?? [])->firstWhere('state', 'running')['key'] ?? null;

        return response()->json([
            'status' => $site->status,
            'error' => $site->status === DemoSite::FAILED ? ($site->error ?? 'other') : null,
            'now' => $now,
            'since' => $site->verified_at?->toIso8601String(),
            'steps' => $steps,
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

    /**
     * A product's choices for the demo's cart sheet, as the store's own API would give them (its options, its variants
     * and which are in stock), read from what the scan kept: the demo adds to no real cart, so it asks no store.
     */
    public function options(string $token, string $id): JsonResponse
    {
        $site = $this->site($token);
        abort_unless($site->status === DemoSite::READY && $site->live() && $site->shop_id !== null, 404);

        $product = app(TenantContext::class)->run($site->shop_id, fn () => CatalogProduct::query()->whereNull('removed_at')->where('external_id', $id)->first());
        abort_if($product === null, 404);
        $payload = (array) $product->payload;

        return response()->json([
            'name' => $product->title,
            'images' => array_values(array_map(fn (array $i): array => ['src' => (string) ($i['url'] ?? '')], array_slice(array_filter((array) ($payload['images'] ?? []), 'is_array'), 0, 1))),
            'attributes' => array_values(array_map(fn (array $a): array => [
                'name' => (string) $a['name'],
                'has_variations' => (bool) ($a['used_for_variations'] ?? false),
                'terms' => array_map(fn ($v): array => ['slug' => (string) $v, 'name' => (string) $v], array_values((array) ($a['values'] ?? []))),
            ], array_filter((array) ($payload['attributes'] ?? []), fn ($a): bool => is_array($a) && isset($a['name'])))),
            'variations' => array_values(array_map(fn (array $v): array => [
                'id' => (string) ($v['external_id'] ?? ''),
                'available' => (bool) ($v['stock']['in_stock'] ?? true),
                'attributes' => array_values((array) ($v['attributes'] ?? [])),
            ], array_filter((array) ($payload['variations'] ?? []), 'is_array'))),
        ])->header('Cache-Control', 'private, max-age=300');
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
