<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('gheops_criteria', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('list_num');
            $table->string('nr');
            $table->string('title');
            $table->text('comorbiditeit')->nullable();
            $table->text('rationale')->nullable();
            $table->text('alternatief')->nullable();
            $table->unsignedSmallInteger('source_page')->nullable();
            $table->timestamps();

            $table->unique(['list_num', 'nr']);
            $table->index('list_num');
        });

        Schema::create('gheops_criterion_ingredient', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gheops_criterion_id')->constrained()->cascadeOnDelete();
            $table->foreignId('active_ingredient_id')->constrained()->cascadeOnDelete();
            $table->unique(['gheops_criterion_id', 'active_ingredient_id'], 'gci_unique');
        });

        Schema::create('gheops_criterion_group', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gheops_criterion_id')->constrained()->cascadeOnDelete();
            $table->string('drug_class');
            $table->unique(['gheops_criterion_id', 'drug_class'], 'gcg_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gheops_criterion_group');
        Schema::dropIfExists('gheops_criterion_ingredient');
        Schema::dropIfExists('gheops_criteria');
    }
};
