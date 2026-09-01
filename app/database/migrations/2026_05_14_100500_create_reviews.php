<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->date('started_on');
            $table->date('due_on')->nullable();
            $table->enum('status', ['draft', 'finalized'])->default('draft');
            $table->timestamp('finalized_at')->nullable();
            $table->text('doctor_summary_md')->nullable();
            $table->timestamps();

            $table->index(['resident_id', 'status']);
        });

        Schema::create('review_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->enum('source', ['gheops', 'phil', 'manual']);
            $table->string('severity')->nullable();
            $table->foreignId('active_ingredient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('gheops_criterion_id')->nullable()->constrained()->nullOnDelete();
            $table->text('title');
            $table->text('body_md')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['review_id', 'source']);
        });

        Schema::create('review_attentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->text('body_md')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_attentions');
        Schema::dropIfExists('review_findings');
        Schema::dropIfExists('reviews');
    }
};
