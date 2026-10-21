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

    // The nightly taste profile (taste:profiles). Code buckets the decayed
    // affinities into facts; Haiku only words them. Scores are the same
    // decayed points as above (one trip taken ≈ 5).
    'taste_profile' => [
        // Fewer taste signals than this (or no liked category) gets no profile.
        'min_signals' => 5,
        // "strong": at least this score and this share of the top score.
        'strong_min' => 5.0,
        'strong_share' => 0.5,
        // "some": at least this score.
        'some_min' => 1.0,
        // "avoid": at or below this score (one recent "not for me" is −4).
        'avoid_max' => -2.0,
        'categories' => 5,
        'veedels' => 3,
        'avoid' => 3,
        // Dayparts holding at least this share of the positive daypart score.
        'daypart_share' => 0.25,
        'dayparts' => 2,
        // Weekend vs weekday activity per day; a lean needs this ratio.
        'week_lean_ratio' => 1.5,
    ],

];
