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
        Schema::create('kiro_master_places', function (Blueprint $table) {
            $table->id();

            $table->string('record_id')->unique();
            $table->string('primary_type', 100)->nullable()->index();
            $table->string('name')->index();
            $table->string('secondary_type', 255)->nullable();
            $table->decimal('rating', 4, 2)->nullable()->index();
            $table->string('price_range', 20)->nullable()->index();
            $table->decimal('price_from', 12, 2)->nullable()->index();
            $table->string('budget_level', 20)->nullable()->index();

            $table->string('address', 255)->nullable();
            $table->string('neighborhood', 150)->nullable()->index();
            $table->string('city', 120)->nullable()->index();
            $table->string('state', 120)->nullable()->index();
            $table->string('postal_code', 20)->nullable()->index();

            $table->string('phone', 60)->nullable()->index();
            $table->string('email', 191)->nullable();
            $table->string('website', 255)->nullable();
            $table->text('google_maps_url')->nullable();
            $table->text('hours')->nullable();
            $table->text('features')->nullable();
            $table->text('review_snippet')->nullable();
            $table->string('legal_name', 255)->nullable();
            $table->string('category', 120)->nullable()->index();
            $table->string('size', 80)->nullable();

            $table->string('merged_from_sources', 255)->nullable();
            $table->unsignedSmallInteger('source_count')->nullable();
            $table->text('source_files')->nullable();

            $table->decimal('latitude', 10, 7)->nullable()->index();
            $table->decimal('longitude', 10, 7)->nullable()->index();
            $table->string('geo_precision', 30)->nullable()->index();

            $table->text('searchable_text')->nullable();
            $table->json('raw_payload')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kiro_master_places');
    }
};
