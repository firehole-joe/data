<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A review queue for offerings whose parsed round count / cost-per-
     * round fails the {@see \App\Services\Feeds\AmmoPricingGuardrail}
     * sanity band, so corrupted figures can be held out of market
     * averages until a human confirms or corrects them.
     */
    public function up(): void
    {
        Schema::table('distributor_products', function (Blueprint $table) {
            $table->boolean('needs_review')->default(false)->index()->after('cost_per_round');
            $table->string('review_reason')->nullable()->after('needs_review');
        });
    }

    public function down(): void
    {
        Schema::table('distributor_products', function (Blueprint $table) {
            $table->dropIndex(['needs_review']);
            $table->dropColumn(['needs_review', 'review_reason']);
        });
    }
};
