<?php

/*
|--------------------------------------------------------------------------
| Personalisation: the behaviour signal ledger (AI roles spec, Role 3)
|--------------------------------------------------------------------------
|
| Only for people who opted in (the `personalisation` user setting, off by
| default). Weights are the spec's starting values, to be tuned against
| retention, not clicks.
|
| Per action:
|   weight     points one signal is worth before decay
|   taste      counts towards the person's affinities (category, Veedel,
|              price band, daypart), decayed with the taste half-life
|   place      counts towards that place's own negative score, decayed with
|              the place-negatives half-life
|   hide_days  hides the place for this many days from the signal
|
*/

return [

    // Raw ledger rows older than this are pruned (model:prune).
    'retention_days' => 90,

    // The pseudonym is an HMAC of the account id. Its own key lets APP_KEY
    // rotate without orphaning every aggregate; it falls back to APP_KEY.
    'pseudonym_key' => env('PERSONALISATION_PSEUDONYM_KEY'),

    'half_life_days' => [
        'taste' => 60,
        'place_negative' => 90,
    ],

    'actions' => [
        'trip_taken' => ['weight' => 5.0, 'taste' => true],
        'saved' => ['weight' => 3.0, 'taste' => true],
        'get_there' => ['weight' => 3.0, 'taste' => true],
        'plan_kept_unedited' => ['weight' => 2.0, 'taste' => true],
        'shared' => ['weight' => 2.0, 'taste' => true],
        'detail_viewed' => ['weight' => 1.0, 'taste' => true],
        // "−2 for that place in that context": a place negative, not a taste.
        'stop_swapped_out' => ['weight' => -2.0, 'place' => true],
        'not_for_me' => ['weight' => -4.0, 'taste' => true, 'place' => true, 'hide_days' => 90],
        'not_now' => ['weight' => -1.0, 'place' => true, 'hide_days' => 7],
        // Worth nothing alone; see ignored_impressions.
        'impression' => ['weight' => 0.0],

        // Place feedback the app already has beyond the spec's table, at the
        // weight of the spec signal each is closest to.
        'more_like_this' => ['weight' => 3.0, 'taste' => true],   // like a save
        'been_liked' => ['weight' => 5.0, 'taste' => true],       // like a trip taken
        'been_disliked' => ['weight' => -4.0, 'taste' => true, 'place' => true, 'hide_days' => 90], // like "not for me"
    ],

    // "Shown 3 times, never touched": −0.3, once, from the third showing.
    // Impressions of one item within a showing window count as one showing.
    'ignored_impressions' => [
        'showings' => 3,
        'weight' => -0.3,
    ],

    // Repeats of one action on one item within this window count once, so a
    // re-rendered list or a resent batch cannot inflate a score.
    'dedupe_minutes' => 60,

    // Context fields aggregates are also split by, beside "all". Signals
    // without the field (e.g. no weather reading) only count towards "all".
    'contexts' => ['week', 'companions', 'weather'],

];
