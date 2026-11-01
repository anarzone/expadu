<?php

namespace App\Privacy;

use App\Models\BureaucracyCase;
use App\Models\BureaucracyPerson;

/**
 * The lock order for a request bound to one person's Paperwork case (ComposerPaperwork): the
 * person, then the case, after the actor's lock and before any consent row, as every family
 * change takes them.
 */
final class CaseLock
{
    /** Called inside a transaction; the case while it's active, else null. */
    public static function lock(int $caseId): ?BureaucracyCase
    {
        $case = BureaucracyCase::query()->find($caseId);
        if ($case?->person_id !== null) {
            BureaucracyPerson::query()->whereKey($case->person_id)->lock('for no key update')->first();
        }

        return $case === null ? null : BureaucracyCase::query()->whereKey($caseId)->where('status', 'active')->lockForUpdate()->first();
    }
}
