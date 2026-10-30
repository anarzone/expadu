<?php

return [
    'schema_version' => 'bureaucracy.onboarding.1',
    'draft_days' => 30,
    'receipt_days' => 30,
    'max_steps' => 4,
    'fact_keys' => [
        'citizenship_group', 'purpose', 'arrival_planned', 'arrival_date', 'moved_in_at',
        'housing_provider_confirmation', 'registration_status', 'entry_mode', 'current_residence_title',
        'visa_expires_at', 'residence_title_expires_at', 'residence_card_expires_at',
        'case_goal', 'german_level', 'sponsor_current_title',
    ],
];
