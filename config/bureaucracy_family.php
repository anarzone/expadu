<?php

return [
    'sharing_notice_version' => '2026-09-08.scoped-family.1',
    'invitation_hours' => 48,
    'grant_days' => 365,
    // Unreviewed dependent requests older than this are erased with their dossier.
    'pending_guardian_days' => 30,
    // Activation requires an actual reviewed guardian procedure and individual evidence.
    'guardian_policy_version' => null,
];
