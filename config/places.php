<?php

return [
    // Enable only after the target catalogue and media workflows are qualified.
    'automation_enabled' => env('PLACES_AUTOMATION_ENABLED', false),

    // Legacy name-only seeds can recreate renamed places without source evidence.
    'curated_seeding_enabled' => env('PLACES_CURATED_SEEDING_ENABLED', false),
];
