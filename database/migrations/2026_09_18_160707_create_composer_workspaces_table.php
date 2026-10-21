<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('composer_workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision')->default(0);
            $table->jsonb('state');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('composer_workspaces');
    }
};
