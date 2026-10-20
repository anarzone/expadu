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
        Schema::create('bureaucracy_workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        Schema::create('bureaucracy_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('bureaucracy_workspaces')->restrictOnDelete();
            $table->foreignId('account_user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->text('display_label')->nullable();
            $table->string('kind', 20)->default('adult');
            $table->string('record_status', 20)->default('active')->index();
            $table->unsignedInteger('record_version')->default(1);
            $table->timestampTz('erased_at')->nullable();
            $table->timestampsTz();
        });
        Schema::create('bureaucracy_workspace_people', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('bureaucracy_workspaces')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('bureaucracy_people')->cascadeOnDelete();
            $table->unique(['workspace_id', 'person_id']);
            $table->timestampsTz();
        });
        Schema::create('bureaucracy_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('bureaucracy_workspaces')->cascadeOnDelete();
            $table->foreignId('inviter_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('recipient_hash', 64)->index();
            $table->string('token_hash', 64)->unique();
            $table->json('requested_scopes');
            $table->string('notice_version');
            $table->foreignId('accepted_person_id')->nullable()->constrained('bureaucracy_people')->nullOnDelete();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });
        Schema::create('bureaucracy_guardian_authorities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('bureaucracy_people')->cascadeOnDelete();
            $table->foreignId('guardian_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->string('policy_version')->nullable();
            $table->text('evidence_reference')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['person_id', 'guardian_user_id', 'status'], 'bureaucracy_guardian_subject_index');
        });
        Schema::create('bureaucracy_access_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('person_id')->constrained('bureaucracy_people')->cascadeOnDelete();
            $table->foreignId('grantee_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('grantor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('guardian_authority_id')->nullable()->constrained('bureaucracy_guardian_authorities')->cascadeOnDelete();
            $table->foreignId('invitation_id')->nullable()->constrained('bureaucracy_invitations')->nullOnDelete();
            $table->string('authority_basis', 40);
            $table->json('scopes');
            $table->string('notice_version');
            $table->unsignedInteger('version')->default(1);
            $table->timestampTz('accepted_at');
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['person_id', 'grantee_user_id', 'revoked_at'], 'bureaucracy_grant_access_index');
        });
        Schema::create('bureaucracy_relationships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('bureaucracy_workspaces')->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('bureaucracy_people')->cascadeOnDelete();
            $table->foreignId('related_person_id')->constrained('bureaucracy_people')->cascadeOnDelete();
            $table->string('type', 40);
            $table->date('effective_from')->nullable();
            $table->date('effective_until')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 80);
            $table->timestampTz('confirmed_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
            $table->index(['person_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['relationships', 'access_grants', 'guardian_authorities', 'invitations', 'workspace_people', 'people', 'workspaces'] as $suffix) {
            Schema::dropIfExists('bureaucracy_'.$suffix);
        }
    }
};
