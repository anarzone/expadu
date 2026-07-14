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
        Schema::create('media_assets', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->default('image');
            $table->string('provider', 50)->index();
            $table->string('provider_asset_id', 500)->nullable();
            $table->char('source_key', 64)->unique();
            $table->string('remote_url', 2048);
            $table->string('source_page_url', 2048)->nullable();
            $table->string('author', 300)->nullable();
            $table->text('attribution')->nullable();
            $table->string('license_code', 100)->nullable();
            $table->string('license_url', 2048)->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->char('checksum', 64)->nullable()->index();
            $table->string('rights_status', 20)->default('pending')->index();
            $table->string('health_status', 20)->default('pending')->index();
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamp('last_seen_at')->nullable()->index();
            $table->timestamp('last_verified_at')->nullable()->index();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['rights_status', 'health_status', 'provider'],
                'media_assets_publication_lookup_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_assets');
    }
};
