<?php

namespace App\Modules\Shopify\Console;

use App\Modules\Shopify\Actions\ConnectPixel;
use App\Modules\Shopify\Models\ShopifyInstall;
use Illuminate\Console\Command;

/**
 * The web pixel on every installed store, or one: after the app's new scopes were deployed and
 * merchants approved them. Opening the app does the same for its store; this does not wait for it.
 */
final class ConnectPixelCommand extends Command
{
    protected $signature = 'shopify:connect-pixel {shop? : the store\'s myshopify domain; every installed store when left out}';

    protected $description = 'Turn on the purchase pixel on Shopify stores';

    public function handle(ConnectPixel $pixel): int
    {
        $installs = ShopifyInstall::query()->whereNull('uninstalled_at')
            ->when($this->argument('shop'), fn ($q, $shop) => $q->where('shop_domain', $shop))->get();

        if ($installs->isEmpty()) {
            $this->error('No installed store.');

            return self::FAILURE;
        }

        foreach ($installs as $install) {
            $this->line($install->shop_domain.': '.($pixel->handle($install) ? 'on' : 'not yet (scope not approved, or see the log)'));
        }

        return self::SUCCESS;
    }
}
