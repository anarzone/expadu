<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v2 pivot: removes the pgvector embedding columns. ML/behavioral
 * personalisation is dead — replaced by the deterministic Day Composer.
 * The pgvector extension itself stays installed (harmless, avoids
 * touching the Postgres image).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['spots', 'events', 'city_news', 'services'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'embedding')) {
                DB::statement("ALTER TABLE {$table} DROP COLUMN embedding");
            }
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'embedding_hash')) {
                DB::statement("ALTER TABLE {$table} DROP COLUMN embedding_hash");
            }
        }

        if (Schema::hasColumn('users', 'preference_vector')) {
            DB::statement('ALTER TABLE users DROP COLUMN preference_vector');
        }
        if (Schema::hasColumn('users', 'preference_vector_updated_at')) {
            DB::statement('ALTER TABLE users DROP COLUMN preference_vector_updated_at');
        }
    }

    public function down(): void
    {
        // Intentionally irreversible — restore from the original
        // add_embedding_columns migration in git history if needed.
    }
};
