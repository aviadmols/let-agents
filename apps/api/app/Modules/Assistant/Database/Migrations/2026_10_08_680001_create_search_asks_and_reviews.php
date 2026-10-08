<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The products the assistant picked from the search results for a question, with why.
        Schema::table('assistant_answers', function (Blueprint $table) {
            $table->json('picks')->nullable()->after('sources');
        });

        // Every question asked in the search box: what was shown, what the assistant said and
        // picked, and what the shopper did next. The shop's report reads from here.
        Schema::create('assistant_search_asks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->date('day');
            $table->string('question', 500);
            $table->json('products');
            $table->string('outcome', 20);
            $table->string('from', 10);
            $table->foreignUlid('answer_id')->nullable()->constrained('assistant_answers')->nullOnDelete();
            $table->json('picks')->nullable();
            $table->boolean('whatsapp_shown')->default(false);
            $table->boolean('whatsapp_clicked')->default(false);
            $table->json('picked')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'day']);
        });

        // Once a day, a model of another family reads yesterday's questions and answers and
        // scores them, for the shop manager.
        Schema::create('assistant_ask_reviews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->date('day');
            $table->unsignedTinyInteger('score')->nullable();
            $table->text('summary')->nullable();
            $table->json('improvements')->nullable();
            $table->json('items');
            $table->json('counts');
            $table->string('status', 20);
            $table->string('reviewed_by', 160)->nullable();
            $table->string('run_id', 26)->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_ask_reviews');
        Schema::dropIfExists('assistant_search_asks');
        Schema::table('assistant_answers', function (Blueprint $table) {
            $table->dropColumn('picks');
        });
    }
};
