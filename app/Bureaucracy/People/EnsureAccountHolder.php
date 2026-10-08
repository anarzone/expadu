<?php

namespace App\Bureaucracy\People;

use App\Models\BureaucracyCase;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyWorkspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

final class EnsureAccountHolder
{
    public function person(User $actor): BureaucracyPerson
    {
        return DB::transaction(function () use ($actor): BureaucracyPerson {
            $user = User::query()->whereKey($actor->id)->lock('for no key update')->firstOrFail();
            $workspace = BureaucracyWorkspace::query()->firstOrCreate(['owner_user_id' => $user->id]);
            $person = BureaucracyPerson::query()->firstOrCreate(['account_user_id' => $user->id], [
                'workspace_id' => $workspace->id, 'display_label' => $user->name, 'kind' => 'adult',
            ]);
            $workspace->people()->syncWithoutDetaching([$person->id]);

            return $person;
        });
    }

    public function dossier(User $actor): BureaucracyCase
    {
        return DB::transaction(function () use ($actor): BureaucracyCase {
            $person = $this->person($actor);
            $case = BureaucracyCase::query()->where('user_id', $actor->id)->lockForUpdate()->first();
            if ($case !== null) {
                if ($case->person_id !== null && $case->person_id !== $person->id) {
                    throw new LogicException('Legacy dossier subject does not match the authenticated account holder.');
                }
                if ($case->person_id === null) {
                    $case->update(['person_id' => $person->id]);
                }

                return $case;
            }
            $existing = $person->dossier()->first();
            if ($existing !== null) {
                throw new LogicException('Canonical dossier has an inconsistent legacy account association.');
            }

            return BureaucracyCase::query()->create(['user_id' => $actor->id, 'person_id' => $person->id, 'status' => 'active']);
        });
    }
}
