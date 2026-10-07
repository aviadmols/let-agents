<?php

use App\Modules\Connections\Support\SiteKeys;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Rega became Let Agents, and the public site key is derived from the token with the product's
     * name in it. The plugin 0.6.0 derives the new key, so every saved connection gets it too. The
     * token itself is unchanged, so a store that installs 0.6.0 stays connected.
     */
    public function up(): void
    {
        foreach (DB::table('store_connections')->get(['id', 'access_token']) as $row) {
            try {
                $token = Crypt::decryptString($row->access_token);
            } catch (Throwable) {
                continue;
            }

            DB::table('store_connections')->where('id', $row->id)->update(['site_key' => SiteKeys::site($token)]);
        }
    }

    public function down(): void
    {
        // The old key cannot be derived here any more; reconnecting the store sets the current one.
    }
};
