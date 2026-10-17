<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Product pictures in the drawer, as Aviad asked (2026-10-17): whole in a light frame at Gueta Avigdor,
 * whose pictures sit on white, and filling their square at Rocks and Gold. Ordinary shop overrides of
 * search.image_fit, changed from the shop's search display screen.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: string}> the fit => [the shop's slug, a piece of its store address] */
    private const CHOICES = ['frame' => ['gueta-avigdor', 'guetaavigdor'], 'cover' => ['rocks-and-gold', 'rocksandgold']];

    public function up(): void
    {
        foreach (self::CHOICES as $fit => [$slug, $site]) {
            foreach ($this->shops($slug, $site) as $shop) {
                DB::table('setting_overrides')->updateOrInsert(['key' => 'search.image_fit', 'scope' => $shop], ['value' => json_encode($fit), 'updated_at' => now(), 'created_at' => now()]);
            }
        }

        Cache::forget('core:setting_overrides');
    }

    public function down(): void
    {
        foreach (self::CHOICES as [$slug, $site]) {
            DB::table('setting_overrides')->where('key', 'search.image_fit')->whereIn('scope', $this->shops($slug, $site))->delete();
        }

        Cache::forget('core:setting_overrides');
    }

    /** @return list<string> */
    private function shops(string $slug, string $site): array
    {
        return DB::table('shops')->where('slug', $slug)
            ->orWhereIn('id', DB::table('store_connections')->where('site_url', 'like', '%'.$site.'%')->select('shop_id'))
            ->pluck('id')->map(fn ($id): string => (string) $id)->unique()->values()->all();
    }
};
