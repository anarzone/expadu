<?php

/*
|--------------------------------------------------------------------------
| Automatic clean-up of sign-ups that never became people (plan C, 13 Nov 2026)
|--------------------------------------------------------------------------
|
| A waitlist entry never confirmed is a stranger's address someone typed
| in, or a bot's; it has no consent behind it. An account whose address is
| never verified can't use the app (verification is required) and is
| usually a bot's. Both go after a while: model:prune deletes waitlist
| entries; accounts:prune-unverified reminds the account first, then
| deletes it as a person deleting their own account would.
|
*/

return [
    'waitlist' => [
        'unconfirmed_days' => 7,
        // Entries from before this day are left for the owner to decide
        // ("keep them for now", 13 Nov 2026).
        'from' => env('CLEANUP_WAITLIST_FROM', '2026-11-14'),
    ],

    'accounts' => [
        'unverified_days' => 30,
        // The reminder goes this many days before the deletion.
        'reminder_days_before' => 7,
    ],
];
