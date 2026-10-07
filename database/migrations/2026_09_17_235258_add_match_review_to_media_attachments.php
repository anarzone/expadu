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
        Schema::table('media_attachments', function (Blueprint $table) {
            $table->string('match_status', 24)->default('pending')->after('is_manually_locked');
            $table->string('match_method', 80)->nullable()->after('match_status');
            $table->jsonb('match_evidence')->nullable()->after('match_method');
            $table->timestampTz('match_reviewed_at')->nullable()->after('match_evidence');
            $table->index(
                ['mediable_type', 'mediable_id', 'role', 'match_status'],
                'media_attachments_publication_lookup',
            );
        });

        Schema::create('media_match_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_attachment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('previous_status', 24)->nullable();
            $table->string('new_status', 24);
            $table->string('match_method', 80)->nullable();
            $table->text('evidence');
            $table->string('reviewer');
            $table->char('fingerprint', 64);
            $table->jsonb('snapshot');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['media_attachment_id', 'created_at']);
            $table->index(['new_status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_match_reviews');

        Schema::table('media_attachments', function (Blueprint $table) {
            $table->dropIndex('media_attachments_publication_lookup');
            $table->dropColumn([
                'match_status',
                'match_method',
                'match_evidence',
                'match_reviewed_at',
            ]);
        });
    }
};
