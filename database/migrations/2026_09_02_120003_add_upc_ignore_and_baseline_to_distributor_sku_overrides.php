<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen `distributor_sku_overrides` into a durable review ledger:
     *
     *  - `upc`: the offering's UPC at the time of the decision, so an
     *    override survives a distributor renumbering its SKU — an
     *    incoming item is matched by UPC as well as by distributor + SKU.
     *  - `is_ignored`: the decision was "ignore" rather than "approve".
     *    An ignored override bypasses the review quarantine on every
     *    future import.
     *  - `baseline_price` / `baseline_description`: a snapshot of the
     *    wholesale cost and raw feed string when the decision was made.
     *    A later import only resurfaces the item for review if the price
     *    drifts materially or the description changes against this
     *    snapshot; otherwise the saved correction is re-applied silently.
     */
    public function up(): void
    {
        Schema::table('distributor_sku_overrides', function (Blueprint $table) {
            $table->string('upc')->nullable()->index()->after('distributor_sku');
            $table->boolean('is_ignored')->default(false)->index()->after('round_count');
            $table->decimal('baseline_price', 10, 4)->nullable()->after('is_ignored');
            $table->text('baseline_description')->nullable()->after('baseline_price');
        });
    }

    public function down(): void
    {
        Schema::table('distributor_sku_overrides', function (Blueprint $table) {
            $table->dropIndex(['upc']);
            $table->dropIndex(['is_ignored']);
            $table->dropColumn(['upc', 'is_ignored', 'baseline_price', 'baseline_description']);
        });
    }
};
