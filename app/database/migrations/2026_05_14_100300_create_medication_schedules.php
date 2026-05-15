<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('medication_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('medication_id')->constrained()->cascadeOnDelete();
            $table->enum('schedule_type', ['chronic', 'temp', 'prn', 'forbidden']);
            $table->date('start_on')->nullable();
            $table->date('end_on')->nullable();
            $table->string('frequency')->nullable();
            $table->string('unit')->nullable();
            $table->json('dosages')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['resident_id', 'schedule_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_schedules');
    }
};
