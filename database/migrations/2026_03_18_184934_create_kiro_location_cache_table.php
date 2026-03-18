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
        Schema::create('kiro_location_cache', function (Blueprint $table) {
            $table->id();

            $table->string('lookup_key')->unique();
            $table->string('query_text', 255);
            $table->decimal('latitude', 10, 7)->nullable()->index();
            $table->decimal('longitude', 10, 7)->nullable()->index();
            $table->string('provider', 40)->default('nominatim');
            $table->string('confidence', 30)->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('hits')->default(0);
            $table->timestamp('last_used_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kiro_location_cache');
    }
};
