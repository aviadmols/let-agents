<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Admin\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The display mode chosen in the top bar's menu, kept on the person: the panel opens in it on any
 * device, as "My profile" sets it.
 */
final class SwitchThemeController
{
    public function __invoke(Request $request, string $theme): Response
    {
        abort_unless(in_array($theme, User::THEMES, true), 404);

        $user = $request->user();
        if ($user instanceof User) {
            $user->forceFill(['theme' => $theme])->save();
        }

        return response()->noContent();
    }
}
