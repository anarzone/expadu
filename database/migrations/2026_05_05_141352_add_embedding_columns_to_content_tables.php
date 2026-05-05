<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add pgvector embedding columns to content tables (Phase 2 personalisation).
 *
 * - 384-dim vectors from sentence-transformers/all-MiniLM-L6-v2.
 * - ivfflat indexes are intentionally NOT created here. They require
 *   `lists = sqrt(rows)` and a populated table for ANALYZE to be useful.
 *   Create them with `CREATE INDEX ... USING ivfflat (...) WITH (lists = N)`
 *   after `php artisan embeddings:backfill` populates each table.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "pgvector extension is not installed on this Postgres server. ".
                "Rebuild the pgsql container from docker/pgsql/Dockerfile (postgis + pgvector), ".
                "then run `php artisan migrate` again. Original error: {$e->getMessage()}"
            );
        }

        foreach (['spots', 'events', 'city_news', 'services'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'embedding')) {
                DB::statement("ALTER TABLE {$table} ADD COLUMN embedding vector(384)");
                DB::statement("ALTER TABLE {$table} ADD COLUMN embedding_hash varchar(64)");
            }
        }

        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'preference_vector')) {
            DB::statement('ALTER TABLE users ADD COLUMN preference_vector vector(384)');
            DB::statement('ALTER TABLE users ADD COLUMN preference_vector_updated_at timestamp');
        }
    }

    public function down(): void
    {
        foreach (['spots', 'events', 'city_news', 'services'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'embedding')) {
                DB::statement("ALTER TABLE {$table} DROP COLUMN embedding");
                DB::statement("ALTER TABLE {$table} DROP COLUMN embedding_hash");
            }
        }

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'preference_vector')) {
            DB::statement('ALTER TABLE users DROP COLUMN preference_vector');
            DB::statement('ALTER TABLE users DROP COLUMN preference_vector_updated_at');
        }
    }
};
