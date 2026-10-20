<?php

namespace App\Places;

use Illuminate\Support\Facades\DB;

class PlaceFactRevision
{
    public function current(): int
    {
        return (int) DB::table('place_fact_revisions')->where('id', 1)->value('revision');
    }

    public function bump(): int
    {
        DB::table('place_fact_revisions')
            ->where('id', 1)
            ->lockForUpdate()
            ->increment('revision', 1, ['updated_at' => now()]);

        return $this->current();
    }
}
