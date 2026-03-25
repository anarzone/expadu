<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->json('situation');
            $table->string('phase')->nullable();
            $table->string('deadline_type')->default('none');
            $table->integer('deadline_days')->nullable();
            $table->string('urgency')->default('medium');
            $table->json('links')->nullable();
            $table->json('documents_required')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
