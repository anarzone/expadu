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
        Schema::create('media_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('media_asset_id')->constrained()->cascadeOnDelete();
            $table->morphs('mediable');
            $table->string('role', 30)->default('hero');
            $table->unsignedSmallInteger('priority')->default(100);
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_manually_locked')->default(false);
            $table->timestamps();

            $table->unique(
                ['media_asset_id', 'mediable_type', 'mediable_id', 'role'],
                'media_attachment_identity_unique',
            );
            $table->index(
                ['mediable_type', 'mediable_id', 'role', 'is_primary'],
                'media_attachment_publication_lookup_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_attachments');
    }
};
