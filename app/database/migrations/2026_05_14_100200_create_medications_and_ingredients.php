<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('active_ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('atc_code')->nullable();
            $table->string('drug_class')->nullable();
            $table->timestamps();
        });

        Schema::create('medications', function (Blueprint $table) {
            $table->id();
            $table->string('cnk', 7)->unique();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('form')->nullable();
            $table->string('atc_code')->nullable();
            $table->string('category')->nullable();
            $table->boolean('is_robot')->default(false);
            $table->string('status')->default('nog-niet-verrijkt');
            $table->string('bron')->nullable();
            $table->json('aliassen')->nullable();
            $table->json('medicatiegroep')->nullable();
            $table->timestamps();
        });

        Schema::create('medication_ingredient', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medication_id')->constrained()->cascadeOnDelete();
            $table->foreignId('active_ingredient_id')->constrained()->cascadeOnDelete();
            $table->string('qty')->nullable();
            $table->string('unit')->nullable();
            $table->string('denominator')->nullable();

            $table->unique(['medication_id', 'active_ingredient_id']);
        });

        Schema::create('drug_class_ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('drug_class');
            $table->foreignId('active_ingredient_id')->constrained()->cascadeOnDelete();

            $table->unique(['drug_class', 'active_ingredient_id']);
            $table->index('drug_class');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drug_class_ingredients');
        Schema::dropIfExists('medication_ingredient');
        Schema::dropIfExists('medications');
        Schema::dropIfExists('active_ingredients');
    }
};
