<?php

namespace App\Modules\Admin\Http\Middleware;

use App\Modules\Admin\Models\DesignPass;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A designer's session ends with its pass: past its day, or revoked by the operator, the next request signs out and
 * says so. Anyone else passes untouched.
 */
final class EndDesignPass
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->hasSession() ? $request->session()->get(DesignPass::SESSION) : null;

        if ($id !== null) {
            $pass = DesignPass::query()->find($id);
            if ($pass === null || ! $pass->open() || Auth::id() !== $pass->user_id) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('admin.design.closed');
            }
        }

        return $next($request);
    }
}
