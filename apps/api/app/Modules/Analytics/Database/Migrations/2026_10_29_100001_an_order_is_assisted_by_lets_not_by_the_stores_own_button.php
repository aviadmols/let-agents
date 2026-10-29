<?php

use App\Modules\Analytics\Models\AnalyticsEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * An order was counted "with Lets" when the visitor had any event before it that was not the search's, and the
 * plugin reports the store's own add-to-cart button too (source "page"): Sela Meir showed seven orders with Lets
 * that Lets never touched (29.10). From now on an add from the store's own page assists nothing (RecordOrder), and
 * the orders already counted so are counted again by the same rule: nothing else of them changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orders = DB::table('analytics_orders')->where('source', 'live')->where('assisted', true)
            ->where('attributed_total', 0)->whereNotNull('visitor_hash')->get(['id', 'shop_id', 'visitor_hash', 'ordered_at']);

        foreach ($orders as $order) {
            $windowStart = Carbon::parse($order->ordered_at)->subDays(30);
            $used = DB::table('analytics_events')->where('shop_id', $order->shop_id)->where('visitor_hash', $order->visitor_hash)
                ->whereBetween('occurred_at', [$windowStart, Carbon::parse($order->ordered_at)->addMinutes(10)])
                ->whereIn('type', ['open', 'click', 'add_to_cart'])
                ->where(fn ($q) => $q->whereNull('source')->orWhereNotIn('source', [AnalyticsEvent::SOURCE_SEARCH, AnalyticsEvent::SOURCE_PAGE]))
                ->exists();

            if (! $used) {
                DB::table('analytics_orders')->where('id', $order->id)->update(['assisted' => false]);
            }
        }
    }

    public function down(): void
    {
        // what was counted again cannot be told apart from what was right
    }
};
