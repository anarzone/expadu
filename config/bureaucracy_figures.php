<?php

/*
 * Volatile official figures referenced by the bureaucracy catalogue.
 *
 * YAML content embeds these as {{figure:key}} — the importer substitutes the
 * value at import time, so the annual January update is THIS file + one
 * re-import, not a content rewrite. Each figure carries its own verified_at;
 * update it only after checking the official source.
 */

return [
    'blue_card_salary' => [
        'value' => '€50,700/year (€4,225/month)',
        'verified_at' => '2026-06-11',
        'source' => 'https://www.stadt-koeln.de/service/produkte/20321/index.html',
        'changes' => 'every January (~5%/year)',
    ],
    'blue_card_salary_shortage' => [
        'value' => '€45,934.20/year (€3,827.85/month)',
        'verified_at' => '2026-06-11',
        'source' => 'https://www.stadt-koeln.de/service/produkte/20321/index.html',
        'changes' => 'every January (~5%/year)',
    ],
    'sperrkonto_students' => [
        'value' => '€11,904',
        'verified_at' => '2026-06-11',
        'source' => 'https://www.stadt-koeln.de/service/produkte/00973/index.html',
        'changes' => 'per academic year (tied to BAföG rates)',
    ],
    'chancenkarte_funds' => [
        'value' => '€13,092 for 12 months (~€1,091/month)',
        'verified_at' => '2026-06-11',
        'source' => 'https://www.make-it-in-germany.com/en/visa-residence/types/chancenkarte',
        'changes' => 'yearly',
    ],
    'student_insurance_monthly' => [
        'value' => 'roughly €145/month',
        'verified_at' => '2026-06-12',
        'source' => 'https://www.studis-online.de/studierendenversicherung/krankenversicherung.php',
        'changes' => 'yearly (base + Zusatzbeitrag + Pflege)',
    ],
    'rundfunkbeitrag_monthly' => [
        'value' => '€18.36/month',
        'verified_at' => '2026-06-11',
        'source' => 'https://www.rundfunkbeitrag.de/welcome/english',
        'changes' => 'rarely (per Staatsvertrag period)',
    ],
];
