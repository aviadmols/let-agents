<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * As Aviad found in Rocks (2026-10-18): "מה מדיניות ההחזרות" and "האם אפשר להחזיר מוצר?" got "not on the
 * site", while the store's "Refund policy" says it. A question on a store topic now finds that page by
 * the topic's words in either language (AnswerSiteQuestion::pagesByWords), so a store question saved as
 * "not on the site" is read again the next time it is asked, as though written by an older prompt.
 * Nothing is asked now; answers given, and the team's own, stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('assistant_answers')->where('outcome', 'no_info')->whereNull('product_id')->whereNull('content_id')
            ->where(fn ($q) => $q->whereNull('source')->orWhere('source', '!=', 'team'))
            ->update(['prompt_version' => 0]);
    }

    public function down(): void
    {
        // Nothing to undo: an answer read again is saved with the prompt it was read by.
    }
};
