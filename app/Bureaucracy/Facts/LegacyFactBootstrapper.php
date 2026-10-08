<?php

namespace App\Bureaucracy\Facts;

use App\Models\BureaucracyCase;
use App\Models\User;

final class LegacyFactBootstrapper
{
    /**
     * @var array<string, string>
     */
    private const PROFILE_FACT_MAP = [
        'citizenship_group' => 'citizenship_group',
        'purpose' => 'purpose',
        'permit_track' => 'permit_track',
        'visa_expires_at' => 'visa_expires_at',
    ];

    public function __construct(
        private ConfirmedBureaucracyAttributes $confirmedAttributes,
        private CaseFactStore $factStore,
    ) {}

    public function bootstrap(User $user): BureaucracyCase
    {
        $attributes = $this->confirmedAttributes->forUser($user);
        $facts = [];

        foreach (self::PROFILE_FACT_MAP as $factKey => $profileAttribute) {
            $facts[$factKey] = $attributes[$profileAttribute] ?? null;
        }

        $storedProfileAttributes = $user->profile_attributes ?? [];

        if (array_key_exists('entry_mode', $storedProfileAttributes)) {
            $facts['entry_mode'] = $storedProfileAttributes['entry_mode'];
        }

        return $this->factStore->bootstrapConfirmedFacts($user, $facts);
    }
}
