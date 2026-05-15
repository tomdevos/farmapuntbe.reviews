<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('residents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('slug');
            $table->string('room')->nullable();
            $table->string('doctor_name')->nullable();
            $table->date('born_on')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->unique(['department_id', 'slug']);
            $table->index(['last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('residents');
    }
};
