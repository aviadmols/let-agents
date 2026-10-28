<?php

use App\Core\Tenancy\TenantContext;
use App\Modules\Connections\Events\StoreAddressMoved;
use App\Modules\Connections\Models\StoreConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sela Meir left its staging copy (sellameir.ussl.co) for www.sellameir.com, as Aviad asked (2026-10-28). Its connection
 * was moved to sellameir.com, which sends every link on to www.sellameir.com, and a second picture of each product and
 * its kept answers' sources still pointed at the staging copy. The connection now reads the store at www.sellameir.com,
 * and both old addresses move to it, like any store's move (MoveCatalogLinks and those after it).
 */
return new class extends Migration
{
    private const TO = 'www.sellameir.com';

    private const FROM = ['sellameir.ussl.co', 'sellameir.com'];

    public function up(): void
    {
        $tenant = app(TenantContext::class);

        foreach (DB::table('store_connections')->where('site_url', 'like', '%sellameir%')->pluck('id') as $id) {
            $connection = $tenant->runUnscoped(fn () => StoreConnection::query()->find($id));
            $host = strtolower((string) parse_url((string) $connection?->site_url, PHP_URL_HOST));

            if ($connection === null || ! in_array(preg_replace('/^www\./', '', $host), self::FROM, true)) {
                continue;
            }

            if ($host !== self::TO) {
                $parts = parse_url((string) $connection->site_url);
                $connection->site_url = ($parts['scheme'] ?? 'https').'://'.self::TO.($parts['path'] ?? '');
                $tenant->runUnscoped(fn () => $connection->save());
            }

            foreach (self::FROM as $from) {
                if ($from !== $host) {
                    event(new StoreAddressMoved((string) $connection->shop_id, $from, self::TO));
                }
            }

            // A shop's domain is kept without "www." (CreateShop::normalizeDomain).
            if (DB::table('shops')->where('id', $connection->shop_id)->value('domain') === 'sellameir.ussl.co'
                && ! DB::table('shops')->where('domain', 'sellameir.com')->exists()) {
                DB::table('shops')->where('id', $connection->shop_id)->update(['domain' => 'sellameir.com', 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        // The staging copy is gone; nothing to move back to.
    }
};
