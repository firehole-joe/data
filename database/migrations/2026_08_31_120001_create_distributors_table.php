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
        Schema::create('distributors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('driver_class');
            $table->enum('transport_type', ['sftp', 'ftp', 'rest_api', 'http_csv']);
            $table->text('connection_settings');
            $table->json('field_mappings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('sync_frequency')->default('hourly');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('distributors');
    }
};
