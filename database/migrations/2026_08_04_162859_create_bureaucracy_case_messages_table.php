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
        Schema::create('bureaucracy_case_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('case_id')->constrained('bureaucracy_cases')->cascadeOnDelete();
            $table->string('role', 20);
            $table->text('content');
            $table->string('operation', 50);
            $table->string('prompt_version', 50);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['case_id', 'role', 'operation', 'created_at'], 'bureaucracy_case_messages_quota_index');
            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bureaucracy_case_messages');
    }
};
