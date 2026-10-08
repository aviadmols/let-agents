<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A second vector for every picture: what it shows, in words. A model that sees pictures writes a
 * short Hebrew description and a few words; the description is embedded with the text model. The
 * picture's own vector says how it looks; this one says what is in it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retrieval_images', function (Blueprint $table) {
            $table->text('caption')->nullable();
            $table->json('caption_words')->nullable();
            // The address, the prompt version and the model it was written with: written again only when one changes.
            $table->string('caption_key', 64)->nullable();
            $table->string('caption_model', 120)->nullable();
            $table->timestamp('captioned_at')->nullable();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE retrieval_images ADD COLUMN caption_embedding vector');
        } else {
            Schema::table('retrieval_images', fn (Blueprint $table) => $table->text('caption_embedding')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('retrieval_images', function (Blueprint $table) {
            $table->dropColumn(['caption', 'caption_words', 'caption_key', 'caption_model', 'captioned_at', 'caption_embedding']);
        });
    }
};
