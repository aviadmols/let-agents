<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sessions, as the shop's panel shows them: each visit to the store, where it came from, and every
 * step in it in order (a page, a search, a question, a save, an add to cart, a purchase). Written by
 * the search window's visit beacon and by the order report; kept for analytics.sessions_keep_days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('session_id', 64);
            $table->string('visitor_hash', 64)->nullable();
            // The visitor's how-manieth visit to this store: 1 is the first.
            $table->unsignedInteger('visit_number')->default(1);
            $table->timestamp('started_at');
            $table->timestamp('last_at');
            $table->string('landing', 300)->nullable();
            $table->string('referrer', 120)->nullable();
            // google, facebook, instagram, whatsapp, email, direct, other; paid when a click id or cpc says so.
            $table->string('source', 20)->default('direct');
            $table->boolean('paid')->default(false);
            $table->string('campaign', 120)->nullable();
            // Only the named campaign parameters (utm_*, gclid, fbclid, ref), never the rest of an address.
            $table->json('params')->nullable();
            $table->string('device', 20)->nullable();
            $table->string('browser', 40)->nullable();
            $table->string('os', 20)->nullable();
            $table->string('ip', 45)->nullable();
            $table->unsignedInteger('pages')->default(0);
            $table->unsignedInteger('steps')->default(0);
            // What happened in it: search, photo, ask, heart, cart, order, lead.
            $table->json('did')->nullable();
            $table->json('searches')->nullable();
            $table->json('asks')->nullable();
            // The searches and questions as one lowercase text, for the screen's "what they searched" filter.
            $table->text('words')->nullable();
            $table->decimal('order_total', 12, 2)->nullable();
            $table->boolean('preview')->default(false);
            $table->timestamps();

            $table->unique(['shop_id', 'session_id']);
            $table->index(['shop_id', 'started_at']);
            $table->index(['shop_id', 'visitor_hash', 'started_at']);
            $table->index(['shop_id', 'ip']);
        });

        Schema::create('analytics_visit_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('visit_id')->constrained('analytics_visits')->cascadeOnDelete();
            $table->string('step_id', 32);
            // view, search, photo, ask, open, heart, cart, order, lead
            $table->string('kind', 12);
            $table->timestamp('at');
            $table->string('path', 300)->nullable();
            $table->string('page_type', 12)->nullable();
            $table->string('item', 64)->nullable();
            $table->string('label', 200)->nullable();
            $table->integer('number')->nullable();
            $table->decimal('value', 12, 2)->nullable();

            $table->unique(['visit_id', 'step_id']);
            $table->index(['shop_id', 'at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_visit_steps');
        Schema::dropIfExists('analytics_visits');
    }
};
