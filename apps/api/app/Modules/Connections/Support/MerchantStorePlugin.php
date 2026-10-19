<?php

namespace App\Modules\Connections\Support;

use App\Core\Tenancy\TenantContext;
use App\Modules\Admin\Contracts\ConnectionFacts;
use App\Modules\Admin\Contracts\StorePlugin;
use App\Modules\Connections\Actions\TestStoreConnection;
use App\Modules\Connections\Enums\ConnectionStatus;
use App\Modules\Connections\Models\StoreConnection;
use App\Modules\Runs\Enums\RunStatus;
use App\Modules\Runs\Models\Run;
use App\Modules\Tenancy\Models\Shop;
use Illuminate\Support\Str;

/**
 * The store plugin from the shop's own panel ("Connection and plan"): the zip to install, a connection
 * token made here and pasted into the plugin's settings (the plugin keeps only its hash, Lets keeps the
 * token, the same as a token made in the plugin and pasted here), and what the connection says now.
 */
final class MerchantStorePlugin implements ConnectionFacts, StorePlugin
{
    /** The prefix the plugin's own tokens carry (LetAgents\Auth\AccessToken::PREFIX). */
    public const TOKEN_PREFIX = 'lat_';

    public function __construct(private readonly TenantContext $tenant) {}

    public function package(): ?array
    {
        $package = PluginPackage::latest();

        return $package === null ? null : ['path' => $package->path, 'filename' => $package->filename, 'version' => $package->version];
    }

    public function issueToken(Shop $shop): string
    {
        $token = self::TOKEN_PREFIX.Str::random(48);

        $this->tenant->run((string) $shop->id, function () use ($shop, $token): void {
            $connection = StoreConnection::query()->firstOrNew(['shop_id' => $shop->id]);

            if (! $connection->exists) {
                $connection->platform = $shop->platform->value;
                $connection->site_url = 'https://'.$shop->domain;
            }

            // The model derives the site key and marks the connection untested until the store answers.
            $connection->access_token = $token;
            $connection->save();
        });

        return $token;
    }

    public function check(Shop $shop): ?bool
    {
        return $this->tenant->run((string) $shop->id, function (): ?bool {
            $connection = StoreConnection::query()->first();

            if ($connection === null) {
                return null;
            }

            app(TestStoreConnection::class)->handle($connection);

            return $connection->fresh()?->status === ConnectionStatus::Connected;
        });
    }

    public function facts(Shop $shop): array
    {
        $connection = StoreConnection::query()->first();
        $synced = Run::query()->where('shop_id', $shop->id)->where('agent', 'catalog.syncer')
            ->where('status', RunStatus::Succeeded)->latest('started_at')->value('started_at');

        $facts = ['last_sync' => $synced === null ? null : now()->parse($synced)];

        if ($connection === null) {
            return $facts + ['has_token' => false];
        }

        return $facts + [
            'connected' => $connection->status === ConnectionStatus::Connected,
            'plugin_version' => is_string($v = $connection->info('plugin.version')) ? $v : null,
            'last_call' => $connection->last_checked_at,
            'has_token' => true,
        ];
    }
}
