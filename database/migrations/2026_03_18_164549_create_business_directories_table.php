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
        Schema::create('business_directories', function (Blueprint $table) {
            $table->id();

            $table->string('giro', 120)->nullable();
            $table->string('nombre_comercial', 180)->nullable();
            $table->string('razon_social', 180)->nullable();
            $table->string('codigo_scian', 10)->nullable()->index();
            $table->string('actividad', 255)->nullable();
            $table->string('tamano', 60)->nullable();
            $table->string('calle', 120)->nullable();
            $table->string('numero_exterior', 20)->nullable();
            $table->string('letra_exterior', 20)->nullable();
            $table->string('numero_interior', 20)->nullable();
            $table->string('letra_interior', 20)->nullable();
            $table->string('colonia', 120)->nullable();
            $table->string('codigo_postal', 10)->nullable()->index();
            $table->string('estado', 100)->nullable()->index();
            $table->string('ciudad', 120)->nullable()->index();
            $table->string('telefono', 30)->nullable();
            $table->string('email', 191)->nullable()->index();
            $table->string('pagina_web', 255)->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('business_directories');
    }
};
