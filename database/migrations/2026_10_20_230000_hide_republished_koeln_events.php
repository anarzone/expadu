<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * koeln.de re-created some posts under new ids (and sometimes new pages),
     * so one occurrence was stored up to three times. Hide the extra copies,
     * by the rule the scraper now follows: one per title, start and venue
     * (the newest), and none without a venue when one with a venue exists.
     */
    public function up(): void
    {
        $venue = "nullif(lower(trim(coalesce(location_name, ''))), 'siehe beschreibung')";

        DB::statement(<<<SQL
            UPDATE events SET status = 'hidden', updated_at = now()
            WHERE id IN (
                SELECT id FROM (
                    SELECT id, row_number() OVER (PARTITION BY lower(title), starts_at, coalesce(nullif({$venue}, ''), '') ORDER BY id DESC) AS copy
                    FROM events WHERE source = 'koeln.de' AND status = 'active'
                ) copies
                WHERE copy > 1
            )
        SQL);

        DB::statement(<<<'SQL'
            UPDATE events e SET status = 'hidden', updated_at = now()
            WHERE e.source = 'koeln.de' AND e.status = 'active'
              AND coalesce(nullif(lower(trim(coalesce(e.location_name, ''))), 'siehe beschreibung'), '') = ''
              AND EXISTS (
                  SELECT 1 FROM events o
                  WHERE o.source = 'koeln.de' AND o.status = 'active' AND o.id <> e.id
                    AND lower(o.title) = lower(e.title) AND o.starts_at = e.starts_at
                    AND coalesce(nullif(lower(trim(coalesce(o.location_name, ''))), 'siehe beschreibung'), '') <> ''
              )
        SQL);
    }

    public function down(): void
    {
        // Data repair; the hidden copies stay in the table.
    }
};
