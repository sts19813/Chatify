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
        Schema::table('kiro_user_contexts', function (Blueprint $table) {
            $table->decimal('location_latitude', 10, 7)->nullable()->after('user_location')->index();
            $table->decimal('location_longitude', 10, 7)->nullable()->after('location_latitude')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kiro_user_contexts', function (Blueprint $table) {
            $table->dropColumn(['location_latitude', 'location_longitude']);
        });
    }
};
