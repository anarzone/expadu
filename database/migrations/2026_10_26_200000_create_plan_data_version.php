<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * One version for everything a Paperwork plan reads (speed plan, 26 Oct 2026):
 * a statement trigger on every bureaucracy table, the catalogue's tasks, users
 * and user_tasks bumps it in the writing transaction, whatever wrote (the app,
 * a command or raw SQL). PlanReadModel remembers a plan only under the version
 * it read, so any write reads afresh. New bureaucracy tables need the trigger
 * too; PlanCacheTest fails until they have it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE TABLE plan_data_version (id smallint PRIMARY KEY CHECK (id = 1), version bigint NOT NULL DEFAULT 0)');
        DB::statement('INSERT INTO plan_data_version (id, version) VALUES (1, 0)');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION bump_plan_data_version() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                UPDATE plan_data_version SET version = version + 1 WHERE id = 1;
                RETURN NULL;
            END;
            $$
            SQL);

        foreach ($this->tables() as $table) {
            DB::statement("CREATE TRIGGER bump_plan_data_version AFTER INSERT OR UPDATE OR DELETE OR TRUNCATE ON \"{$table}\" FOR EACH STATEMENT EXECUTE FUNCTION bump_plan_data_version()");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tables() as $table) {
            DB::statement("DROP TRIGGER IF EXISTS bump_plan_data_version ON \"{$table}\"");
        }
        DB::statement('DROP FUNCTION IF EXISTS bump_plan_data_version()');
        DB::statement('DROP TABLE IF EXISTS plan_data_version');
    }

    /** @return list<string> */
    private function tables(): array
    {
        $bureaucracy = array_column(DB::select("SELECT tablename FROM pg_tables WHERE schemaname = current_schema() AND tablename LIKE 'bureaucracy\\_%' ORDER BY tablename"), 'tablename');

        return [...$bureaucracy, 'tasks', 'users', 'user_tasks'];
    }
};
