<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A human-confirmed round count for a specific distributor SKU.
     *
     * When a reviewer approves a flagged offering from the dashboard the
     * corrected `round_count` is recorded here so every subsequent feed
     * import re-applies it automatically — the offering is priced against
     * the confirmed count and never re-enters the review queue for the
     * same reason.
     */
    public function up(): void
    {
        Schema::create('distributor_sku_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distributor_id')->constrained()->cascadeOnDelete();
            $table->string('distributor_sku');
            $table->unsignedInteger('round_count');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->unique(['distributor_id', 'distributor_sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distributor_sku_overrides');
    }
};
