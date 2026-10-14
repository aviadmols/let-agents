<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every model call with its prompt, what was sent and what came back, for the operator to open
 * from the pipeline. Kept runs.call_log_days (30 by default), pruned nightly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('run_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('run_id')->nullable()->constrained('runs')->nullOnDelete();
            $table->foreignUlid('shop_id')->nullable()->constrained('shops')->cascadeOnDelete();
            $table->string('agent', 80)->nullable();
            $table->string('action', 80)->nullable();
            // chat | vision | web | image
            $table->string('kind', 10);
            $table->string('provider', 40);
            $table->string('model', 120);
            $table->longText('system')->nullable();
            $table->longText('sent');
            $table->longText('received')->nullable();
            $table->text('error')->nullable();
            $table->boolean('ok')->default(true);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('run_calls');
    }
};
