<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The tags a page offers a shopper ("חומרים לבניית דק", "עצים לבנייה בחוץ"), each a search
        // the page's shopper is likely to want next. Written at night by one model, checked by a
        // model of another family against what each tag would show; the results themselves are
        // searched when the page bank is built, so they follow stock.
        Schema::create('search_page_tags', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->string('page_type', 20);
            $table->string('external_id', 64);
            $table->string('title', 300);
            $table->json('tags');
            $table->json('hidden_labels');
            $table->string('fingerprint', 64);
            $table->string('written_by', 160)->nullable();
            $table->string('checked_by', 160)->nullable();
            $table->timestamps();

            $table->unique(['shop_id', 'page_type', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('search_page_tags');
    }
};
