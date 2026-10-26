<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a store's team decided about its word families (the word families screen): a family approved, fixed or made by
 * hand (approved: kept as it is, its words in no written family), one that is wrong (rejected: its words never one
 * family again), a searched word set aside (hidden). Read over the written families (StoreFamilies), never written over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('search_family_choices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('status', 12);
            $table->json('words');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['shop_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_family_choices');
    }
};
