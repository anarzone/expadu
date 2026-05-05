<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Add pgvector embedding columns to content tables (Phase 2 personalisation).
 *
 * - 384-dim vectors from sentence-transformers/all-MiniLM-L6-v2.
 * - ivfflat indexes are intentionally NOT created here.
 *
 * Resilience: if pgvector binaries are not installed at the OS level on
 * this Postgres server, the migration logs a warning and marks itself
 * ran without doing anything. This prevents container restart loops
 * when entrypoint.sh runs `migrate --force` against an environment that
 * has not been rebuilt yet.
 *
 * To finish Phase 2 on such an environment:
 *   1. Rebuild Postgres from docker/pgsql/Dockerfile so pgvector is available.
 *   2. Add a follow-up migration that re-runs the column adds — Schema::hasColumn
 *      guards keep it idempotent for environments that already have them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->pgvectorAvailable()) {
            Log::warning('Skipping embedding-columns migration — pgvector extension not installed. '
                .'Rebuild Postgres from docker/pgsql/Dockerfile and add a follow-up migration to apply columns.');

            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

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

    private function pgvectorAvailable(): bool
    {
        try {
            $row = DB::selectOne("SELECT 1 AS available FROM pg_available_extensions WHERE name = 'vector' LIMIT 1");

            return $row !== null;
        } catch (Throwable) {
            return false;
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
