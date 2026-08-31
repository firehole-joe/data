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
        Schema::create('distributor_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distributor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('master_ammunition_id')->nullable()->constrained('master_ammunition')->nullOnDelete();
            $table->string('distributor_sku')->index();
            $table->string('raw_upc')->nullable()->index();
            $table->string('raw_mfr_part_number')->nullable()->index();
            $table->string('raw_description');
            $table->decimal('wholesale_price', 10, 2)->default(0.00);
            $table->decimal('map_price', 10, 2)->nullable();
            $table->decimal('msrp_price', 10, 2)->nullable();
            $table->unsignedInteger('quantity_available')->default(0);
            $table->boolean('is_in_stock')->default(false)->index();
            $table->timestamp('last_feed_update_at')->nullable();
            $table->timestamps();

            $table->unique(['distributor_id', 'distributor_sku']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('distributor_products');
    }
};
