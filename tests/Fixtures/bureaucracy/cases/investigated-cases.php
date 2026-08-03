<?php

return [
    'working on D visa and applying for a first Blue Card' => [
        'persona' => 'case-blue-card-first',
        'coverage' => 'matched',
        'matched' => [
            'case.bc.first_application.prepare',
            'case.bc.first_application.submit',
        ],
        'unknown' => [],
        'sections' => [
            'do_now' => [
                'case.bc.first_application.prepare',
                'case.bc.first_application.submit',
            ],
        ],
        'deadlines' => [
            'case.bc.first_application.prepare' => '2026-10-01',
            'case.bc.first_application.submit' => '2026-10-01',
        ],
    ],
    'joining spouse while the sponsor Blue Card is pending' => [
        'persona' => 'case-family-sponsor-pending',
        'coverage' => 'needs_information',
        'matched' => [
            'case.family.first_permit.prepare',
            'case.family.register_address',
        ],
        'unknown' => ['case.family.first_permit.sponsor_pending_review'],
        'missing' => ['livelihood_secured'],
        'sections' => [
            'do_now' => [
                'case.family.first_permit.prepare',
                'case.family.register_address',
            ],
            'information_needed' => ['case.family.first_permit.sponsor_pending_review'],
        ],
        'deadlines' => [
            'case.family.first_permit.prepare' => '2026-10-01',
        ],
        'forbidden_phrases' => ['will be issued', 'is guaranteed', 'automatically qualifies'],
    ],
    'Blue Card holder with B1 and twelve qualifying months' => [
        'persona' => 'case-blue-card-b1-12',
        'coverage' => 'matched',
        'matched' => ['case.bc.settlement.track_21_months'],
        'unknown' => [],
        'sections' => [
            'coming_up' => ['case.bc.settlement.track_21_months'],
        ],
        'required_phrase' => 'not yet eligible',
    ],
    'spouse of an 18c holder after three years' => [
        'persona' => 'case-spouse-18c-three-years',
        'coverage' => 'matched',
        'matched' => ['case.family.settlement.spouse_18c_option'],
        'unknown' => [],
        'sections' => [
            'options' => ['case.family.settlement.spouse_18c_option'],
        ],
        'required_phrase' => 'authority must verify',
    ],
    'spouse approaching renewal after almost four years' => [
        'persona' => 'case-family-renewal-four-years',
        'coverage' => 'matched',
        'matched' => [
            'case.family.renew.continuing_household',
            'case.family.settlement.general_coming_up',
            'case.family.settlement.spouse_18c_option',
        ],
        'unknown' => [],
        'sections' => [
            'do_now' => ['case.family.renew.continuing_household'],
            'coming_up' => ['case.family.settlement.general_coming_up'],
            'options' => ['case.family.settlement.spouse_18c_option'],
        ],
        'deadlines' => [
            'case.family.renew.continuing_household' => '2026-09-01',
        ],
        'absent' => ['case.family.independent_after_separation'],
    ],
    'unsupported current residence title' => [
        'persona' => 'case-unsupported-title',
        'coverage' => 'not_covered',
        'matched' => [],
        'unknown' => [],
        'universal' => ['case.bc.verify_status_source'],
        'sections' => [
            'not_covered' => [],
        ],
    ],
];
