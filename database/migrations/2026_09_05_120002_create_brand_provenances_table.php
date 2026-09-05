<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ownership / country-of-origin classification for ammunition brands,
     * consumed by the public supply-summary API's `provenance_filter` and
     * `include_provenance_breakdown` options.
     *
     * `master_ammunition` gets no denormalised column — provenance is
     * resolved dynamically by joining this table on the brand name
     * (see App\Models\MasterAmmunition::brandProvenance()), so a brand's
     * classification only ever lives in one place and a re-seed is the
     * whole update.
     */
    public function up(): void
    {
        Schema::create('brand_provenances', function (Blueprint $table) {
            $table->id();
            $table->string('brand_name')->unique();
            $table->enum('provenance', [
                'american_owned_american_made',
                'foreign_owned_us_manufactured',
                'imported_or_repackaged',
            ]);
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_provenances');
    }
};
