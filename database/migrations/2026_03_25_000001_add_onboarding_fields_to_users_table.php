<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('city')->nullable();
            $table->string('situation')->nullable();
            $table->date('arrival_date')->nullable();
            $table->string('german_level')->nullable();
            $table->json('speaks')->nullable();
            $table->timestamp('onboarded_at')->nullable();
            $table->string('avatar_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'city',
                'situation',
                'arrival_date',
                'german_level',
                'speaks',
                'onboarded_at',
                'avatar_path',
            ]);
        });
    }
};
