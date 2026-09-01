<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('review_findings', function (Blueprint $table) {
            // The pharmacist's own explanation, kept apart from the generated
            // body_md so a refresh can rewrite the generated half untouched.
            $table->text('note_md')->nullable()->after('body_md');
            // Stable key per finding so gheops/phil refreshes can upsert
            // instead of delete-then-insert (which lost notes + dismissals).
            $table->string('fingerprint', 64)->nullable()->after('note_md');
            $table->index(['review_id', 'fingerprint']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            // Distinguishes "screened, no matches" from "never screened".
            $table->timestamp('gheops_screened_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('review_findings', function (Blueprint $table) {
            $table->dropIndex(['review_id', 'fingerprint']);
            $table->dropColumn(['note_md', 'fingerprint']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn('gheops_screened_at');
        });
    }
};
