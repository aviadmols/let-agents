<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A page or a post of the store's own site its team stopped the assistant from using (Sella, 12.11: a 2024 post on the
 * book week's offers answered "what offers do you have?" in 2026). Kept by the content's id, which a sync keeps, with its
 * title and address as they were, so the team sees what it stopped and can bring it back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_ignored_sources', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('content_id', 64);
            $table->string('title', 300);
            $table->string('url', 1000)->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
            $table->unique(['shop_id', 'content_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_ignored_sources');
    }
};
