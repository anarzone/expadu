<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Events become machine-composable: clean AI-derived fields stored once
 * at ingest (summary/chips/relevance), RRULE recurrence expanded on
 * read, venues as first-class rows linked to seeded places, and
 * per-occurrence reminders. The composer anchors on these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('veedel', 100)->nullable();
            $table->string('address_text', 300)->nullable();
            $table->foreignId('place_id')->nullable()->constrained('spots')->nullOnDelete();
            $table->timestamps();
            $table->unique(['name', 'address_text']);
        });

        Schema::table('events', function (Blueprint $table) {
            $table->string('source_uid', 200)->nullable();
            $table->text('summary_en')->nullable();
            $table->string('tip_en', 300)->nullable();
            $table->string('language', 12)->nullable(); // en | de | mixed | none_needed
            $table->json('chips')->nullable();
            $table->float('relevance')->nullable(); // null = not yet AI-processed
            $table->boolean('needs_review')->default(false)->index();
            $table->string('status', 12)->default('active')->index(); // active | expired | hidden
            $table->string('recurrence', 200)->nullable(); // RRULE subset
            $table->timestamp('recurrence_until')->nullable();
            $table->foreignId('venue_id')->nullable()->constrained('venues')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->unique(['source', 'source_uid']);
        });

        Schema::create('event_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->timestamp('occurrence_start');
            $table->unsignedInteger('offset_minutes'); // remind this long before start
            $table->timestamp('remind_at')->index(); // occurrence_start - offset
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'event_id', 'occurrence_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_reminders');
        Schema::table('events', function (Blueprint $table) {
            $table->dropUnique(['source', 'source_uid']);
            $table->dropConstrainedForeignId('venue_id');
            $table->dropColumn([
                'source_uid', 'summary_en', 'tip_en', 'language', 'chips',
                'relevance', 'needs_review', 'status', 'recurrence',
                'recurrence_until', 'verified_at',
            ]);
        });
        Schema::dropIfExists('venues');
    }
};
