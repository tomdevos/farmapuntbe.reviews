<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finding_note_templates', function (Blueprint $table) {
            $table->id();
            // Derived from the finding (sorted medication pair / criterium id /
            // product slug), so the same finding always lands on the same row.
            $table->string('key', 64)->unique();
            $table->enum('source', ['gheops', 'phil', 'manual']);
            $table->string('label');
            $table->text('note_md');
            $table->unsignedInteger('times_used')->default(1);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });

        Schema::table('review_findings', function (Blueprint $table) {
            // Set when the text was filled in from the library; cleared as soon
            // as the pharmacist saves the field, so "still to check" is visible.
            $table->timestamp('note_suggested_at')->nullable()->after('fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('review_findings', function (Blueprint $table) {
            $table->dropColumn('note_suggested_at');
        });

        Schema::dropIfExists('finding_note_templates');
    }
};
