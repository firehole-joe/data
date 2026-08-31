<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('master_ammunition', function (Blueprint $table) {
            $table->id();
            $table->string('upc', 14)->nullable()->index();
            $table->string('mfr_part_number')->index();
            $table->string('manufacturer')->index();
            $table->string('name');
            $table->string('caliber')->index();
            $table->unsignedInteger('bullet_weight_gr')->nullable()->index();
            $table->string('bullet_type')->nullable();
            $table->string('case_material')->default('Brass');
            $table->unsignedInteger('rounds_per_box')->default(50);
            $table->boolean('is_tracked_in_report')->default(true);
            $table->timestamps();

            $table->unique(['manufacturer', 'mfr_part_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_ammunition');
    }
};
