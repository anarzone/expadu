<?php

namespace App\Console\Commands\Bureaucracy;

use App\Bureaucracy\People\PersonDataLifecycle;
use App\Models\BureaucracyGuardianAuthority;
use App\Models\BureaucracyPerson;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('bureaucracy:erase-unguarded-dependents')]
#[Description('Erase dependent dossiers that no guardian can manage any more')]
class EraseUnguardedDependentsCommand extends Command
{
    public function handle(PersonDataLifecycle $lifecycle): int
    {
        $pendingSince = now()->utc()->subDays(max(1, (int) config('bureaucracy_family.pending_guardian_days', 30)));
        $erased = 0;
        BureaucracyPerson::query()->where('kind', 'dependent')->whereNull('account_user_id')->where('record_status', 'active')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from((new BureaucracyGuardianAuthority)->getTable())
                ->whereColumn('person_id', 'bureaucracy_people.id')->whereNull('revoked_at')
                ->where(fn ($live) => $live->where(fn ($approved) => $approved->where('status', 'approved')->where('expires_at', '>', now()->utc()))
                    ->orWhere(fn ($pending) => $pending->where('status', 'pending')->where('created_at', '>', $pendingSince))))
            ->orderBy('id')->limit(200)->pluck('id')
            ->each(function (int $id) use ($lifecycle, &$erased): void {
                // Re-checked under the row lock, so a guardian approved meanwhile keeps the record.
                $erased += $lifecycle->eraseUnguardedDependent($id, keepRecentPending: true) ? 1 : 0;
            });
        $this->info("Erased {$erased} dependent records that no guardian can manage.");

        return self::SUCCESS;
    }
}
