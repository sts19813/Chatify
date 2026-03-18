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
        Schema::create('kiro_user_contexts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();

            $table->string('user_location')->nullable()->index();
            $table->string('price_preference', 30)->nullable()->index();
            $table->json('preference_tags')->nullable();
            $table->json('interest_patterns')->nullable();
            $table->text('chat_summary')->nullable();
            $table->string('last_intent', 50)->nullable()->index();
            $table->text('last_query')->nullable();
            $table->json('last_result_ids')->nullable();
            $table->timestamp('last_interaction_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kiro_user_contexts');
    }
};
