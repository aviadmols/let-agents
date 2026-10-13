<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A post goes to the store's own blog (Shopify or WordPress) as a draft, with what search engines
 * read: its SEO title and description, focus keyword, excerpt, tags and questions with answers.
 * Where it went is kept, so a second publish updates it. Every post a model writes is counted,
 * for the monthly number the operator sets per store.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('improvement_posts', function (Blueprint $table) {
            // seo_title, meta_description, focus_keyword, excerpt, tags, faq [{q, a}]
            $table->json('seo')->nullable();
            // platform, id, url, admin_url, status (draft|failed), error, at
            $table->json('remote')->nullable();
        });

        Schema::create('improvement_post_writes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('proposal_id', 26);
            $table->string('run_id', 26)->nullable();
            $table->timestamp('created_at');

            $table->index(['shop_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('improvement_post_writes');
        Schema::table('improvement_posts', function (Blueprint $table) {
            $table->dropColumn(['seo', 'remote']);
        });
    }
};
