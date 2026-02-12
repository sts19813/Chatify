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
        Schema::table('movements', function (Blueprint $table) {

            $table->string('category')->nullable()->after('description');

            $table->string('currency', 10)
                  ->default('MXN')
                  ->after('category');

            $table->date('movement_date')
                  ->nullable()
                  ->after('currency');

            $table->text('notes')
                  ->nullable()
                  ->after('movement_date');

            // Índices para analytics (MUY recomendable)
            $table->index(['user_id', 'type']);
            $table->index(['user_id', 'movement_date']);
            $table->index(['user_id', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('movements', function (Blueprint $table) {

            $table->dropIndex(['user_id', 'type']);
            $table->dropIndex(['user_id', 'movement_date']);
            $table->dropIndex(['user_id', 'category']);

            $table->dropColumn([
                'category',
                'currency',
                'movement_date',
                'notes'
            ]);
        });
    }
};
