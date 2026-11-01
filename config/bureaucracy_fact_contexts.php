<?php

return [
    // A date on the previous document does not establish the new document's validity.
    'current_residence_title' => ['residence_title_expires_at', 'residence_card_expires_at'],
    // These belong to one home. A real move starts new requirements; a typo correction does not.
    'moved_in_at' => ['registration_status', 'housing_provider_confirmation'],
];
