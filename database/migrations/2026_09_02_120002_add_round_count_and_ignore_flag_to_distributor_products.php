<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two review-resolution fields on the offering:
     *
     *  - `round_count`: a per-offering rounds-per-unit count, set when a
     *    reviewer approves a flagged offering. NULL means "fall back to
     *    `master_ammunition.rounds_per_box`"; a value overrides it for
     *    this listing's cost-per-round and every pricing rollup.
     *  - `is_ignored`: the reviewer has permanently dismissed this
     *    offering. Ignored rows are held out of the flagged view and out
     *    of every market calculation.
     */
    public function up(): void
    {
        Schema::table('distributor_products', function (Blueprint $table) {
            $table->unsignedInteger('round_count')->nullable()->after('cost_per_round');
            $table->boolean('is_ignored')->default(false)->index()->after('review_reason');
        });
    }

    public function down(): void
    {
        Schema::table('distributor_products', function (Blueprint $table) {
            $table->dropIndex(['is_ignored']);
            $table->dropColumn(['round_count', 'is_ignored']);
        });
    }
};
