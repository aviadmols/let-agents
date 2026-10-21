<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What each shop used in a month, per meter (MonthlyUsage): the plan's monthly allowances count here. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_usage', function (Blueprint $table): void {
            $table->id();
            $table->string('shop_id', 26);
            $table->char('month', 7);
            $table->string('meter', 40);
            $table->unsignedInteger('used')->default(0);
            $table->timestamps();
            $table->unique(['shop_id', 'month', 'meter']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_usage');
    }
};
