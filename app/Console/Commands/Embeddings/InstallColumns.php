<?php

namespace App\Console\Commands\Embeddings;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-shot installer for the pgvector extension + embedding columns.
 *
 * Why a command instead of a migration: the original migration
 * (2026_05_05_141352) skipped itself with a logged warning on prod
 * because pgvector binaries weren't installed at deploy time, and
 * Laravel marked it ran. Once Coolify rebuilds pgsql with the
 * pgvector-enabled image, run this command — it's idempotent,
 * can be re-run safely, and reports exactly what it changed.
 *
 *   ssh root@PROD 'docker exec app-... php artisan embeddings:install-columns'
 */
#[Signature('embeddings:install-columns {--dry-run : Show what would change without writing}')]
#[Description('Install pgvector + embedding columns on tables that need them. Idempotent.')]
class InstallColumns extends Command
{
    public function handle(): int
    {
        if (! $this->pgvectorAvailable()) {
            $this->error('pgvector is not available on this Postgres server.');
            $this->line('Install it first: rebuild pgsql from docker/pgsql/Dockerfile (or update Coolify');
            $this->line('to point at ghcr.io/anarzone/expadu/pgsql:latest), then re-run this command.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $this->info($dryRun ? 'DRY RUN — no changes will be written.' : 'Applying changes…');

        $changes = [];

        if (! $this->extensionInstalled()) {
            $changes[] = 'CREATE EXTENSION vector';
            if (! $dryRun) {
                DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
            }
        }

        $contentTables = ['spots', 'events', 'city_news', 'services'];
        foreach ($contentTables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (! Schema::hasColumn($table, 'embedding')) {
                $changes[] = "{$table}.embedding vector(384)";
                if (! $dryRun) {
                    DB::statement("ALTER TABLE {$table} ADD COLUMN embedding vector(384)");
                }
            }
            if (! Schema::hasColumn($table, 'embedding_hash')) {
                $changes[] = "{$table}.embedding_hash varchar(64)";
                if (! $dryRun) {
                    DB::statement("ALTER TABLE {$table} ADD COLUMN embedding_hash varchar(64)");
                }
            }
        }

        if (Schema::hasTable('users')) {
            if (! Schema::hasColumn('users', 'preference_vector')) {
                $changes[] = 'users.preference_vector vector(384)';
                if (! $dryRun) {
                    DB::statement('ALTER TABLE users ADD COLUMN preference_vector vector(384)');
                }
            }
            if (! Schema::hasColumn('users', 'preference_vector_updated_at')) {
                $changes[] = 'users.preference_vector_updated_at timestamp';
                if (! $dryRun) {
                    DB::statement('ALTER TABLE users ADD COLUMN preference_vector_updated_at timestamp');
                }
            }
        }

        if (empty($changes)) {
            $this->info('Nothing to do — all columns already in place.');

            return self::SUCCESS;
        }

        $this->newLine();
        foreach ($changes as $change) {
            $this->line('  '.($dryRun ? '~' : '+').' '.$change);
        }
        $this->newLine();
        $this->info($dryRun
            ? 'Run without --dry-run to apply '.count($changes).' change(s).'
            : 'Applied '.count($changes).' change(s). Next: php artisan embeddings:backfill');

        return self::SUCCESS;
    }

    private function pgvectorAvailable(): bool
    {
        try {
            $row = DB::selectOne("SELECT 1 AS available FROM pg_available_extensions WHERE name = 'vector' LIMIT 1");

            return $row !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    private function extensionInstalled(): bool
    {
        try {
            $row = DB::selectOne("SELECT 1 AS installed FROM pg_extension WHERE extname = 'vector' LIMIT 1");

            return $row !== null;
        } catch (\Throwable) {
            return false;
        }
    }
}
