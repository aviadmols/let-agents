<?php

use App\Core\Http\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Railway and Cloudflare terminate TLS in front of the app. Trusting their forwarded
        // headers gives correct https URLs and real client IPs for the anonymous rate limit.
        $middleware->trustProxies(at: '*');

        // What every response says about itself: who may frame it, no guessed types, https kept (1.11).
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A page of the panels that was not found says why in the log: a 404 is never reported by default, and one met in
        // onboarding (App Store review, 17.11) left no trace to find it by. Nothing shown changes, and it never throws.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            try {
                if ($request->is('merchant*', 'operator*', 'livewire-*', 'shopify*')) {
                    $cause = $e->getPrevious() ?? $e;
                    $frames = [];
                    foreach (array_slice($cause->getTrace(), 0, 60) as $f) {
                        if (isset($f['file']) && ! str_contains($f['file'], 'laravel'.DIRECTORY_SEPARATOR.'framework')) {
                            $frames[] = basename($f['file']).':'.($f['line'] ?? '');
                        }
                    }
                    Log::warning('panel 404', [
                        'host' => $request->getHost(), 'path' => $request->path(), 'method' => $request->method(),
                        'referer' => $request->headers->get('referer'), 'livewire' => $request->hasHeader('X-Livewire'),
                        'user' => $request->user()?->getAuthIdentifier(),
                        'cause' => $cause::class.': '.mb_substr($cause->getMessage(), 0, 300),
                        'at' => basename($cause->getFile()).':'.$cause->getLine(), 'frames' => array_slice($frames, 0, 14),
                    ]);
                }
            } catch (Throwable) {
                // the page answers as it would have
            }

            return null;
        });
    })->create();
