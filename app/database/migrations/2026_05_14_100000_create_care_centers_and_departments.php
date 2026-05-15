<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('care_centers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('address')->nullable();
            $table->string('contact')->nullable();
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('care_center_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();

            $table->unique(['care_center_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
        Schema::dropIfExists('care_centers');
    }
};
