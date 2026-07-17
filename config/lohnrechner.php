<?php

/*
 * German net-salary constants for 2026 — the netto-brutto tool. Same trust
 * rule as config/rheinlandtarif.php: every figure pinned against an official
 * or authoritative source, re-verify on the source before changing.
 *
 * Verified 2026-07-13 against:
 * - §32a EStG (tariff): https://www.gesetze-im-internet.de/estg/__32a.html
 *   (zone continuity checked: 17,799 → 1,034.87 and 69,878/69,879 → ±0.4 €)
 * - Beitragsbemessungsgrenzen 2026: https://www.bundesregierung.de/breg-de/aktuelles/beitragsgemessungsgrenzen-2386514
 * - Average Zusatzbeitrag 2.9% / PV 3.6% (+0.6 childless) / AV 2.6%:
 *   GKV-Spitzenverband Rechengrößen 2026 + BMG announcement
 * - Soli Freigrenze 2026 (20,350 single): https://www.tk.de/firmenkunden/service/fachthemen/fachthema-beitraege/solidaritaetszuschlag-2075802
 */

return [
    'year' => 2026,
    'verified_at' => '2026-07-16',
    'source_tariff' => 'https://www.gesetze-im-internet.de/estg/__32a.html',
    'source_sv' => 'https://www.bundesregierung.de/breg-de/aktuelles/beitragsgemessungsgrenzen-2386514',
    'source_39b' => 'https://www.gesetze-im-internet.de/estg/__39b.html',
    'source_care' => 'https://www.gesetze-im-internet.de/sgb_11/__55.html',

    // §32a EStG income-tax tariff (annual zvE, euros).
    'tariff' => [
        'basic_allowance' => 12348,
        'zone2_end' => 17799,          // (914.51·y + 1400)·y, y = (zvE−12348)/10⁴
        'zone2' => ['a' => 914.51, 'b' => 1400],
        'zone3_end' => 69878,          // (173.10·z + 2397)·z + 1034.87, z = (zvE−17799)/10⁴
        'zone3' => ['a' => 173.10, 'b' => 2397, 'c' => 1034.87],
        'zone4_end' => 277825,         // 0.42·x − 11135.63
        'zone4' => ['rate' => 0.42, 'c' => 11135.63],
        'zone5' => ['rate' => 0.45, 'c' => 19470.38],
    ],

    // Solidarity surcharge: 0 below the Freigrenze, then min(5.5% of tax,
    // 11.9% of the excess) — the §4 SolzG mitigation zone.
    'soli' => [
        'free_limit_single' => 20350,
        'rate' => 0.055,
        'mitigation_rate' => 0.119,
    ],

    // Employee-side social insurance, 2026.
    'social' => [
        'bbg_health_year' => 69750,    // KV + PV ceiling
        'bbg_pension_year' => 101400,  // RV + AV ceiling (8,450/mo)
        'health_general' => 0.146,     // paid half/half
        'health_zusatz_avg' => 0.029,  // average Zusatzbeitrag, half/half
        'care_total' => 0.036,         // employee half 1.8%
        'care_childless_surcharge' => 0.006, // employee-only, 23+ without kids
        'care_child_discount' => 0.0025,     // §55(3) SGB XI: per child #2–#5 under 25
        'care_saxony_shift' => 0.005,        // §58(3) SGB XI: employee carries 1pp alone
        'pension_total' => 0.186,      // employee half 9.3%
        'unemployment_total' => 0.026, // employee half 1.3%
    ],

    /*
     * Full wage-tax (Lohnsteuer) parameters — verified 2026-07-16 against
     * §39b/§32(6)/§24b EStG and §55/§58 SGB XI, then cross-checked to the
     * cent against the official BMF calculator (three reference cases:
     * class I 524.50, class V 944.91, class I + child + church 531.83/26.60
     * at €4,000/month, Zusatzbeitrag 2.9%).
     */
    'wage_tax' => [
        // Vorsorgepauschale uses the ermäßigter KV rate (§243 SGB V):
        // employee 7.0% + Zusatz/2 — NOT the 7.3% actually withheld.
        'kv_reduced_rate' => 0.140,
        // §39b(2) S.7 corridor for classes V/VI.
        'v56' => ['w1' => 14071, 'w2' => 34939, 'w3' => 222260],
        'single_parent_relief' => 4260,  // §24b — built into class II
        'child_allowance_full' => 9756,  // §32(6): (3,414 + 1,464) × 2 per full counter
        'vsp_min_rate' => 0.12,          // Mindestvorsorgepauschale
        'vsp_min_cap' => 1900,
        'vsp_min_cap_iii' => 3000,
    ],

    // Wage deductions in the simplified monthly estimate.
    'allowances' => [
        'employee_lump_sum' => 1230,   // Arbeitnehmer-Pauschbetrag
        'special_expenses' => 36,      // Sonderausgaben-Pauschbetrag
    ],

    'church_rates' => ['default' => 0.09, 'by_bw' => 0.08],
];
