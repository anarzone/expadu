<?php

/*
 * Cologne Stadtteile ("Veedel") grouped by Bezirk. The grouping doubles as
 * the week-1 adjacency model: a user's default discovery area is their
 * Veedel plus the rest of its Bezirk. PostGIS polygons replace this in
 * a later phase (spots:assign-veedel).
 */
return [
    'Innenstadt' => [
        'Altstadt-Nord', 'Altstadt-Süd', 'Neustadt-Nord', 'Neustadt-Süd', 'Deutz',
    ],
    'Rodenkirchen' => [
        'Bayenthal', 'Godorf', 'Hahnwald', 'Immendorf', 'Marienburg', 'Meschenich',
        'Raderberg', 'Raderthal', 'Rodenkirchen', 'Rondorf', 'Sürth', 'Weiß', 'Zollstock',
    ],
    'Lindenthal' => [
        'Braunsfeld', 'Junkersdorf', 'Klettenberg', 'Lindenthal', 'Lövenich',
        'Müngersdorf', 'Sülz', 'Weiden', 'Widdersdorf',
    ],
    'Ehrenfeld' => [
        'Bickendorf', 'Bocklemünd/Mengenich', 'Ehrenfeld', 'Neuehrenfeld', 'Ossendorf', 'Vogelsang',
    ],
    'Nippes' => [
        'Bilderstöckchen', 'Longerich', 'Mauenheim', 'Niehl', 'Nippes', 'Riehl', 'Weidenpesch',
    ],
    'Chorweiler' => [
        'Blumenberg', 'Chorweiler', 'Esch/Auweiler', 'Fühlingen', 'Heimersdorf', 'Lindweiler',
        'Merkenich', 'Pesch', 'Roggendorf/Thenhoven', 'Seeberg', 'Volkhoven/Weiler', 'Worringen',
    ],
    'Porz' => [
        'Eil', 'Elsdorf', 'Ensen', 'Finkenberg', 'Gremberghoven', 'Grengel', 'Langel', 'Libur',
        'Lind', 'Poll', 'Porz', 'Urbach', 'Wahn', 'Wahnheide', 'Westhoven', 'Zündorf',
    ],
    'Kalk' => [
        'Brück', 'Höhenberg', 'Humboldt/Gremberg', 'Kalk', 'Merheim', 'Neubrück',
        'Ostheim', 'Rath/Heumar', 'Vingst',
    ],
    'Mülheim' => [
        'Buchforst', 'Buchheim', 'Dellbrück', 'Dünnwald', 'Flittard', 'Höhenhaus',
        'Holweide', 'Mülheim', 'Stammheim',
    ],
];
