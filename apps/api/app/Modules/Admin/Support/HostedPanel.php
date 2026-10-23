<?php

namespace App\Modules\Admin\Support;

use App\Modules\Admin\Contracts\PanelHost;
use Illuminate\Http\Request;

/** Whether the panel is shown inside a platform's own admin (PanelHost, 'admin.panel_hosts'), for this request. */
final class HostedPanel
{
    public static function inside(?Request $request = null): bool
    {
        $request ??= request();

        foreach (app()->tagged(PanelHost::TAG) as $host) {
            if ($host instanceof PanelHost && $host->hosts($request)) {
                return true;
            }
        }

        return false;
    }
}
