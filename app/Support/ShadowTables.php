<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Replace whole Postgres tables without blocking their readers: load into a
 * shadow copy ("{table}__next"), give it the live table's indexes and keys,
 * then swap the two by rename in one short step.
 *
 * Readers keep the live tables until the swap, which holds its exclusive
 * locks only for the renames. Index and constraint names are restored, and
 * serial sequences move to the new table, so migrations that name them keep
 * working. Must run inside a transaction: a failure anywhere rolls the
 * shadows back and leaves the live tables as they were.
 */
class ShadowTables
{
    private const NEXT = '__next';

    private const OLD = '__old';

    /**
     * How long the swap may wait for a lock before it backs off and retries.
     * Readers queue behind a waiting rename, so this caps how long any of
     * them can stall.
     */
    private const LOCK_TIMEOUT = '1s';

    private const SWAP_ATTEMPTS = 8;

    /**
     * @param  list<string>  $tables
     */
    public function __construct(private readonly array $tables) {}

    public static function shadowOf(string $table): string
    {
        return $table.self::NEXT;
    }

    /** Create an empty shadow of every table: same columns, defaults and checks, no indexes yet. */
    public function create(): void
    {
        foreach ($this->tables as $table) {
            $shadow = self::shadowOf($table);
            DB::statement("DROP TABLE IF EXISTS {$shadow}");
            DB::statement("CREATE TABLE {$shadow} (LIKE {$table} INCLUDING ALL EXCLUDING INDEXES)");
        }
    }

    /**
     * Build the live tables' indexes and keys on the loaded shadows (cheaper
     * after the bulk load than during it), then gather planner statistics.
     */
    public function index(): void
    {
        foreach ($this->tables as $table) {
            $shadow = self::shadowOf($table);

            foreach ($this->indexes($table) as $index) {
                $prefix = ($index['unique'] ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ').$index['name'].' ON public.'.$table.' ';
                if (! str_starts_with($index['definition'], $prefix)) {
                    throw new RuntimeException("Unexpected index definition for {$index['name']}: {$index['definition']}");
                }

                $next = $this->name($index['name'], self::NEXT);
                DB::statement(
                    ($index['unique'] ? 'CREATE UNIQUE INDEX ' : 'CREATE INDEX ').$next.' ON public.'.$shadow.' '
                    .substr($index['definition'], strlen($prefix))
                );

                if ($index['constraint'] !== null) {
                    $kind = $index['constraint'] === 'p' ? 'PRIMARY KEY' : 'UNIQUE';
                    DB::statement("ALTER TABLE {$shadow} ADD CONSTRAINT {$next} {$kind} USING INDEX {$next}");
                }
            }

            DB::statement("ANALYZE {$shadow}");
        }
    }

    /**
     * Swap every shadow in under its live table's name in one step, so readers
     * see the whole old set or the whole new one. Retries when a long reader
     * holds a table past the lock timeout.
     */
    public function swap(): void
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                DB::transaction(function (): void {
                    DB::statement('SET LOCAL lock_timeout = '.DB::getPdo()->quote(self::LOCK_TIMEOUT));

                    foreach ($this->tables as $table) {
                        $this->swapOne($table);
                    }
                });

                return;
            } catch (QueryException $exception) {
                // 55P03 = lock_not_available (the timeout fired).
                if ($attempt >= self::SWAP_ATTEMPTS || ($exception->errorInfo[0] ?? null) !== '55P03') {
                    throw $exception;
                }

                usleep(500_000 * $attempt);
            }
        }
    }

    /** Drop any shadows left behind (after a failure outside a transaction). */
    public function discard(): void
    {
        foreach ($this->tables as $table) {
            DB::statement('DROP TABLE IF EXISTS '.self::shadowOf($table));
        }
    }

    private function swapOne(string $table): void
    {
        $shadow = self::shadowOf($table);
        $old = $table.self::OLD;
        $indexes = $this->indexes($table);

        DB::statement("ALTER TABLE {$table} RENAME TO {$old}");
        DB::statement("ALTER TABLE {$shadow} RENAME TO {$table}");

        foreach ($indexes as $index) {
            // Renaming a key's index renames its constraint too.
            DB::statement("ALTER INDEX {$index['name']} RENAME TO ".$this->name($index['name'], self::OLD));
            DB::statement('ALTER INDEX '.$this->name($index['name'], self::NEXT)." RENAME TO {$index['name']}");
        }

        // The shadow's serial columns already draw on the live sequences; make
        // the new table their owner so dropping the old one keeps them.
        foreach ($this->ownedSequences($old) as $column => $sequence) {
            DB::statement("ALTER SEQUENCE {$sequence} OWNED BY {$table}.{$column}");
        }

        DB::statement("DROP TABLE {$old}");
    }

    /**
     * @return list<array{name: string, definition: string, unique: bool, constraint: string|null}>
     */
    private function indexes(string $table): array
    {
        return array_map(fn (object $row): array => [
            'name' => (string) $row->name,
            'definition' => (string) $row->definition,
            'unique' => (bool) $row->is_unique,
            'constraint' => $row->constraint_type !== null ? (string) $row->constraint_type : null,
        ], DB::select(
            "SELECT i.relname AS name, pg_get_indexdef(i.oid) AS definition, x.indisunique AS is_unique, c.contype AS constraint_type
               FROM pg_index x
               JOIN pg_class i ON i.oid = x.indexrelid
               LEFT JOIN pg_constraint c ON c.conindid = i.oid AND c.contype IN ('p', 'u')
              WHERE x.indrelid = ?::regclass
              ORDER BY i.relname",
            ["public.{$table}"],
        ));
    }

    /**
     * Sequences owned by a table's columns, by column name.
     *
     * @return array<string, string>
     */
    private function ownedSequences(string $table): array
    {
        $sequences = [];
        foreach (DB::select(
            'SELECT a.attname AS column_name, pg_get_serial_sequence(?, a.attname) AS sequence
               FROM pg_attribute a
              WHERE a.attrelid = ?::regclass AND a.attnum > 0 AND NOT a.attisdropped',
            ["public.{$table}", "public.{$table}"],
        ) as $row) {
            if ($row->sequence !== null) {
                $sequences[(string) $row->column_name] = (string) $row->sequence;
            }
        }

        return $sequences;
    }

    /** Postgres truncates identifiers past 63 bytes; refuse rather than collide. */
    private function name(string $name, string $suffix): string
    {
        $full = $name.$suffix;
        if (strlen($full) > 63) {
            throw new RuntimeException("Identifier too long for a shadow swap: {$full}");
        }

        return $full;
    }
}
