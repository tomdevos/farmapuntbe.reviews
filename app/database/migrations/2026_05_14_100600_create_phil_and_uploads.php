<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('phil_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resident_id')->constrained()->cascadeOnDelete();
            $table->timestamp('fetched_at');
            $table->string('cnk_hash', 40);
            $table->string('raw_html_path')->nullable();
            $table->json('parsed_json')->nullable();
            $table->timestamps();

            $table->index(['resident_id', 'fetched_at']);
        });

        Schema::create('phil_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('phil_interaction_id')->constrained()->cascadeOnDelete();
            $table->enum('severity', ['ernstig', 'matig', 'gering', 'voeding']);
            $table->string('med_a');
            $table->string('med_b')->nullable();
            $table->text('advies')->nullable();
            $table->timestamps();
        });

        Schema::create('medication_schema_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('care_center_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('stored_path');
            $table->timestamp('uploaded_at');
            $table->timestamp('processed_at')->nullable();
            $table->enum('status', ['pending', 'processing', 'processed', 'failed'])->default('pending');
            $table->json('summary')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('review_exports', function (Blueprint $table) {
            $table->id();
            $table->enum('scope', ['resident', 'department', 'care_center']);
            $table->unsignedBigInteger('scope_id');
            $table->enum('format', ['pdf', 'docx']);
            $table->string('path');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['scope', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_exports');
        Schema::dropIfExists('medication_schema_uploads');
        Schema::dropIfExists('phil_findings');
        Schema::dropIfExists('phil_interactions');
    }
};
