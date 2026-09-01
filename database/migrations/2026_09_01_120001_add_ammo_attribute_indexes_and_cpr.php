<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the denormalised cost-per-round column the supply dashboard
     * sorts and aggregates on, plus the covering indexes that keep the
     * caliber / projectile / grain filters and the master-grouping join
     * off a full table scan.
     */
    public function up(): void
    {
        Schema::table('distributor_products', function (Blueprint $table) {
            $table->decimal('cost_per_round', 10, 4)->nullable()->after('wholesale_price');

            $table->index(['master_ammunition_id', 'is_in_stock'], 'dp_master_stock_idx');
            $table->index('cost_per_round', 'dp_cpr_idx');
        });

        Schema::table('master_ammunition', function (Blueprint $table) {
            $table->index('bullet_type', 'ma_bullet_type_idx');
            $table->index(['caliber', 'bullet_type', 'bullet_weight_gr'], 'ma_spec_idx');
        });
    }

    public function down(): void
    {
        Schema::table('master_ammunition', function (Blueprint $table) {
            $table->dropIndex('ma_spec_idx');
            $table->dropIndex('ma_bullet_type_idx');
        });

        Schema::table('distributor_products', function (Blueprint $table) {
            $table->dropIndex('dp_cpr_idx');
            $table->dropIndex('dp_master_stock_idx');
            $table->dropColumn('cost_per_round');
        });
    }
};
