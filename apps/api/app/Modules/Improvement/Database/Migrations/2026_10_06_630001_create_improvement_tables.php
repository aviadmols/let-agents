<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One look a day at what a shop's shoppers searched and asked: the evidence code gathered,
        // whether models were needed, and the short report the shop gets.
        Schema::create('improvement_reviews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->date('day');
            $table->string('status', 20);
            $table->json('evidence');
            $table->unsignedSmallInteger('proposed')->default(0);
            $table->unsignedSmallInteger('accepted')->default(0);
            $table->unsignedSmallInteger('applied')->default(0);
            $table->text('digest');
            $table->boolean('mailed')->default(false);
            $table->string('run_id', 26)->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'day']);
        });

        // What the review proposes for the site, what the second model said about it, and what
        // the team decided. A proposal the second model refused is kept, with its reason.
        Schema::create('improvement_proposals', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('review_id')->constrained('improvement_reviews')->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('title', 300);
            $table->json('detail');
            $table->json('evidence');
            $table->string('fingerprint', 64);
            $table->string('status', 20);
            $table->string('proposed_by', 160);
            $table->string('audited_by', 160)->nullable();
            $table->string('audit_reason', 300)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index(['shop_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('improvement_proposals');
        Schema::dropIfExists('improvement_reviews');
    }
};
