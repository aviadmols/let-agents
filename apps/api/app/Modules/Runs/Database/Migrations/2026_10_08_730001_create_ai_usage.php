<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every model call, one row each: which shop, which agent, which provider and model, the tokens
 * and the cost. A run that asks a writer and a checker of two families is two rows, so spend can
 * be read by model. Kept longer than the runs themselves: it is the bill.
 *
 * Runs recorded before the ledger are copied in once, one row each, under the run's last model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('run_id')->nullable()->constrained('runs')->nullOnDelete();
            $table->foreignUlid('shop_id')->nullable()->constrained('shops')->cascadeOnDelete();
            $table->string('agent', 80);
            $table->string('action', 80);
            $table->string('provider', 40);
            $table->string('model', 120);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->date('day');
            $table->timestamp('created_at')->nullable();

            $table->index(['day', 'shop_id']);
            $table->index(['shop_id', 'day']);
        });

        DB::table('runs')
            ->where(fn ($q) => $q->where('input_tokens', '>', 0)->orWhere('output_tokens', '>', 0)->orWhere('cost_usd', '>', 0))
            ->whereNotNull('model')
            ->orderBy('id')
            ->chunk(500, function ($runs): void {
                DB::table('ai_usage')->insert($runs->map(fn ($run): array => [
                    'run_id' => $run->id,
                    'shop_id' => $run->shop_id,
                    'agent' => $run->agent,
                    'action' => $run->action,
                    'provider' => (string) ($run->provider ?? ''),
                    'model' => (string) $run->model,
                    'input_tokens' => (int) $run->input_tokens,
                    'output_tokens' => (int) $run->output_tokens,
                    'cache_read_tokens' => (int) $run->cache_read_tokens,
                    'cost_usd' => (float) ($run->cost_usd ?? 0),
                    'day' => substr((string) $run->started_at, 0, 10),
                    'created_at' => $run->started_at,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};
