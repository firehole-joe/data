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
        Schema::create('price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('distributor_product_id')->constrained()->cascadeOnDelete();
            $table->decimal('wholesale_price', 10, 2);
            $table->unsignedInteger('quantity_available');
            $table->date('recorded_date')->index();
            $table->timestamps();

            $table->unique(['distributor_product_id', 'recorded_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_histories');
    }
};
