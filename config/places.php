<?php

return [
    // Enable only after the target catalogue and media workflows are qualified.
    'automation_enabled' => env('PLACES_AUTOMATION_ENABLED', false),

    // Legacy name-only seeds can recreate renamed places without source evidence.
    'curated_seeding_enabled' => env('PLACES_CURATED_SEEDING_ENABLED', false),

    // Reviewed OSM green-space objects that pass the size/name rules but are
    // not public destinations or duplicate an existing record (7 Oct 2026).
    'green_space_exclusions' => [
        'way/1167638870', // Blackfoot Hochseilgarten: commercial climbing park
        'way/26630846', // "Heide": ambiguous name for a recreation ground
        'way/30075267', // Im Wasserfeld: sports ground
        'relation/3115310', // Ruderinsel: rowing club grounds
        'way/25215238', // Thielenbruch forest: inside "Thielenbruch und Thurner Wald"
        'relation/12794303', // Thielenbruch reserve: inside "Thielenbruch und Thurner Wald"
        'way/948232592', // Flittarder Rheinaue: already the "NSG Flittarder Rheinaue" attraction
        'way/11025898', // Blücherparkweiher: pond inside Blücherpark
        'relation/7362563', // Volksgartenweiher: pond inside Volksgarten
        // Forstbotanischer Garten sections, not separate destinations
        'way/1382866867', 'relation/19067822', 'relation/19067821', 'way/1382866866', 'relation/19067823',
        // "Wald für Köln" plantation plots
        'relation/19020809', 'relation/19020808',
        // "Wildniswald": one generic name for three unrelated patches
        'relation/3533839', 'way/861088325', 'way/233681793',
    ],
];
