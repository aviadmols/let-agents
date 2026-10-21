<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The plans a shop pays for, and the plan each shop is on. A plan's limits are ceilings on other
 * modules' features and settings, by key (PlanCeilings): a feature it leaves out is off, a number
 * is capped. A shop with no plan has no ceiling (the shops from before plans, a custom deal).
 * The plans as lets.co.il/pricing sells them, October 2026; the operator edits them as rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 32)->unique();
            $table->string('name', 60);
            $table->decimal('price_usd', 10, 2)->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('public')->default(true);
            $table->json('limits');
            $table->timestamps();
        });

        Schema::table('shops', function (Blueprint $table): void {
            $table->string('plan', 32)->nullable()->index();
        });

        // Pro only: WhatsApp when there is no answer, the daily report, the daily site check
        $belowPro = ['assistant.search_whatsapp' => false, 'widget.whatsapp' => false, 'assistant.ask_review' => false, 'improvement.daily_review' => false];
        // Growth and up: "About this product", saved lists by phone, shopper sessions
        $belowGrowth = $belowPro + ['widget.on_products' => false, 'assistant.on_products' => false, 'shortlist.wishlist_phone' => false, 'analytics.sessions' => false];

        $plans = [
            ['essentials', 'Essentials', 49, $belowGrowth + ['search.photos' => false], [
                'assistant.answers_per_month' => 300, 'search.photos_per_month' => 0, 'catalog.max_products' => 500,
                'catalog.max_content_items' => 20, 'assistant.post_knowledge_pages' => 20, 'assistant.post_knowledge_posts' => 20,
                'admin.max_team_members' => 1, 'analytics.history_days' => 30, 'search.theme' => 'lets',
            ]],
            ['starter', 'Starter', 99, $belowGrowth, [
                'assistant.answers_per_month' => 1000, 'search.photos_per_month' => 150, 'catalog.max_products' => 2000,
                'catalog.max_content_items' => 50, 'assistant.post_knowledge_pages' => 50, 'assistant.post_knowledge_posts' => 50,
                'admin.max_team_members' => 2, 'analytics.history_days' => 30, 'search.theme' => 'lets',
            ]],
            ['growth', 'Growth', 199, $belowPro, [
                'assistant.answers_per_month' => 3000, 'search.photos_per_month' => 500, 'catalog.max_products' => 10000,
                'catalog.max_content_items' => 300, 'admin.max_team_members' => 5, 'analytics.history_days' => 365,
            ]],
            ['pro', 'Pro', 299, [], [
                'assistant.answers_per_month' => 5000, 'search.photos_per_month' => 1000, 'catalog.max_products' => 25000,
                'admin.max_team_members' => 10, 'analytics.history_days' => 730,
            ]],
            ['enterprise', 'Enterprise', null, [], []],
        ];

        foreach ($plans as $i => [$code, $name, $price, $features, $settings]) {
            DB::table('plans')->insert([
                'code' => $code,
                'name' => $name,
                'price_usd' => $price,
                'sort' => $i + 1,
                'public' => $price !== null,
                'limits' => json_encode(['features' => (object) $features, 'settings' => (object) $settings]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table): void {
            $table->dropIndex(['plan']);
            $table->dropColumn('plan');
        });

        Schema::dropIfExists('plans');
    }
};
