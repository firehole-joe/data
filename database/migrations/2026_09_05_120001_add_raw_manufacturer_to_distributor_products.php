<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The manufacturer / brand string exactly as the distributor's feed
     * states it (e.g. Zanders' `MFG` column). Kept alongside the parsed
     * description so the matcher can prefer an explicit vendor brand over
     * a description-prefix guess, and so a later re-derivation
     * ({@see \App\Console\Commands\BackfillZandersBrandsCommand}) has the
     * original value to work from. NULL when the feed carries no such
     * column.
     */
    public function up(): void
    {
        Schema::table('distributor_products', function (Blueprint $table) {
            $table->string('raw_manufacturer')->nullable()->after('raw_mfr_part_number');
        });
    }

    public function down(): void
    {
        Schema::table('distributor_products', function (Blueprint $table) {
            $table->dropColumn('raw_manufacturer');
        });
    }
};
