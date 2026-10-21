<?php

namespace App\Personalisation;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Reads one person's aggregates (written nightly by signals:aggregate). */
final class Affinities
{
    public function for(User $user): AffinitySnapshot
    {
        if (! $user->personalises()) {
            return AffinitySnapshot::empty();
        }

        $subject = Pseudonym::for($user);

        $affinities = [];
        foreach (DB::table('user_affinities')->where('subject_key', $subject)->get(['dimension', 'key', 'context', 'score', 'signals_count']) as $row) {
            $affinities[$row->dimension][$row->context][$row->key] = ['score' => round((float) $row->score, 4), 'signals' => (int) $row->signals_count];
        }

        $negatives = [];
        $hidden = [];
        $now = now();
        foreach (DB::table('user_place_negatives')->where('subject_key', $subject)->get(['item_type', 'item_id', 'context', 'score', 'hidden_until']) as $row) {
            $negatives[$row->item_type][(int) $row->item_id][$row->context] = round((float) $row->score, 4);
            if ($row->hidden_until !== null && ($until = CarbonImmutable::parse($row->hidden_until))->greaterThan($now)) {
                $hidden[$row->item_type][(int) $row->item_id] = $until;
            }
        }

        return new AffinitySnapshot($affinities, $negatives, $hidden);
    }
}
