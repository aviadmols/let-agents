<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Try-On (docs/try-on.md): a shopper's photo with what the gate found in it, each try of a product
 * on it, and every step of every try with its prompt, its answer, its pictures and what it cost.
 * Pictures live on the Try-On disk; rows hold their paths.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('try_on_photos', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            // The shopper, as a hash salted per shop: no name, no IP.
            $table->string('visitor_hash', 64);
            // Of the bytes uploaded: the same photo again is not looked at again.
            $table->string('photo_hash', 64);
            $table->string('path', 255)->nullable();
            $table->unsignedSmallInteger('width')->default(0);
            $table->unsignedSmallInteger('height')->default(0);
            // ok or rejected, and why (a key of try_on::try_on.rejected)
            $table->string('status', 12);
            $table->string('reject_code', 40)->nullable();
            // What the gate saw: subject, people, quality. And the groups the photo can serve.
            $table->json('gate')->nullable();
            $table->json('usable')->nullable();
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->timestamp('consent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'visitor_hash', 'photo_hash']);
            $table->index(['shop_id', 'created_at']);
        });

        Schema::create('try_on_jobs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('photo_id')->constrained('try_on_photos')->cascadeOnDelete();
            $table->foreignUlid('product_id')->constrained('catalog_products')->cascadeOnDelete();
            $table->string('visitor_hash', 64);
            // The product's try-on class (ring, top, furniture...) and its group (jewelry, apparel, furniture).
            $table->string('tryon_class', 20);
            $table->string('group', 12);
            // What the shopper answered before it was made: which finger, which wrist, where in the room.
            $table->json('answers')->nullable();
            // The photo, the product picture, the answers, the model and the prompts' versions: the same try is never made twice.
            $table->string('fingerprint', 64);
            // queued, working, ready, failed
            $table->string('status', 12);
            $table->string('stage', 20)->nullable();
            $table->string('failure', 40)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('result_path', 255)->nullable();
            $table->decimal('cost_usd', 10, 6)->default(0);
            $table->foreignUlid('run_id')->nullable()->constrained('runs')->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'created_at']);
            $table->index(['shop_id', 'fingerprint']);
            $table->index(['shop_id', 'visitor_hash', 'created_at']);
        });

        Schema::create('try_on_steps', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignUlid('photo_id')->nullable()->constrained('try_on_photos')->cascadeOnDelete();
            $table->foreignUlid('job_id')->nullable()->constrained('try_on_jobs')->cascadeOnDelete();
            // code_gate, vision_gate, prompt, generate, check
            $table->string('step', 20);
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->string('status', 12);
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            // The prompt exactly as sent.
            $table->longText('prompt')->nullable();
            $table->string('provider', 20)->nullable();
            $table->string('model', 120)->nullable();
            $table->unsignedInteger('tokens_in')->default(0);
            $table->unsignedInteger('tokens_out')->default(0);
            $table->decimal('seconds', 8, 2)->default(0);
            $table->decimal('cost_usd', 10, 6)->default(0);
            // Pictures the step read or made, by role: photo, product, result, sheet.
            $table->json('images')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamps();

            $table->index(['job_id', 'created_at']);
            $table->index(['photo_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('try_on_steps');
        Schema::dropIfExists('try_on_jobs');
        Schema::dropIfExists('try_on_photos');
    }
};
