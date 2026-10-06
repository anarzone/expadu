<?php

return [
    // The application's search area, never a person's location or route origin.
    // Keep aliases alongside the area so English and local place names rank alike.
    'search_default' => [
        'city' => env('GEOCODING_DEFAULT_CITY', 'Köln'),
        'aliases' => array_map('trim', explode(',', env('GEOCODING_CITY_ALIASES', 'Köln,Koeln,Cologne'))),
        'lat' => (float) env('GEOCODING_DEFAULT_LAT', 50.9375),
        'lng' => (float) env('GEOCODING_DEFAULT_LNG', 6.9603),
    ],
];
