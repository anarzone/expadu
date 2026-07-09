<?php

namespace App\Bureaucracy;

use App\Enums\Situation;
use App\Models\User;

/**
 * The canonical roster of expat personas — one per branch × relevant entry
 * mode, plus a few state showcases (planning, temporary housing). This is the
 * single source of truth shared by several consumers so they can never drift:
 *   - CoverageCommand / BureaucracyCoverageTest (the invariant sweep);
 *   - BureaucracyDemoController (the in-app, read-only "view as any expat"
 *     switcher — an in-memory User, nothing written);
 *   - SeedPersonasCommand / QA\PersonaController (provisioning + the live
 *     "become this persona" QA switcher — REAL writes via persistableProfile()).
 *
 * Each persona is a flat spec. userFor() reads it through an in-memory User
 * and never persists anything; persistableProfile() derives the same shape
 * for callers that DO intend to write it to a real user row.
 */
class BureaucracyPersonas
{
    /**
     * The coverage roster: every branch × entry mode that must yield a valid
     * path. Deliberately excludes cross-cutting modifiers (housing, licence,
     * life events) — CoverageCommand multiplies those in for its --full sweep.
     *
     * @return list<array{key: string, label: string, situation: Situation, is_eu: bool, path: ?string, entry_mode: string}>
     */
    public static function coverage(): array
    {
        return [
            ['key' => 'eu-employee', 'label' => 'EU employee', 'situation' => Situation::EuEmployee, 'is_eu' => true, 'path' => null, 'entry_mode' => 'has_permit'],
            ['key' => 'eu-student', 'label' => 'EU student', 'situation' => Situation::Student, 'is_eu' => true, 'path' => null, 'entry_mode' => 'has_permit'],
            ['key' => 'eu-freelancer', 'label' => 'EU freelancer (liberal)', 'situation' => Situation::Freelancer, 'is_eu' => true, 'path' => null, 'entry_mode' => 'has_permit'],
            ['key' => 'eu-gewerbe', 'label' => 'EU freelancer (Gewerbe)', 'situation' => Situation::Freelancer, 'is_eu' => true, 'path' => 'freelancer_gewerbe', 'entry_mode' => 'has_permit'],
            ['key' => 'neu-employee-dvisa', 'label' => 'Non-EU employee · standard · D-visa', 'situation' => Situation::NonEuEmployee, 'is_eu' => false, 'path' => null, 'entry_mode' => 'd_visa'],
            ['key' => 'neu-employee-visafree', 'label' => 'Non-EU employee · standard · visa-free', 'situation' => Situation::NonEuEmployee, 'is_eu' => false, 'path' => null, 'entry_mode' => 'visa_free'],
            ['key' => 'neu-employee-haspermit', 'label' => 'Non-EU employee · standard · has permit', 'situation' => Situation::NonEuEmployee, 'is_eu' => false, 'path' => null, 'entry_mode' => 'has_permit'],
            ['key' => 'neu-bluecard', 'label' => 'Non-EU employee · Blue Card · D-visa', 'situation' => Situation::NonEuEmployee, 'is_eu' => false, 'path' => 'non_eu_employee_blue_card', 'entry_mode' => 'd_visa'],
            ['key' => 'neu-chancenkarte', 'label' => 'Non-EU employee · Chancenkarte · visa-free', 'situation' => Situation::NonEuEmployee, 'is_eu' => false, 'path' => 'non_eu_employee_chancenkarte', 'entry_mode' => 'visa_free'],
            ['key' => 'neu-student', 'label' => 'Non-EU student · D-visa', 'situation' => Situation::Student, 'is_eu' => false, 'path' => null, 'entry_mode' => 'd_visa'],
            ['key' => 'neu-freelancer', 'label' => 'Non-EU freelancer (liberal §21.5) · D-visa', 'situation' => Situation::Freelancer, 'is_eu' => false, 'path' => null, 'entry_mode' => 'd_visa'],
            ['key' => 'neu-gewerbe', 'label' => 'Non-EU freelancer (Gewerbe §21.1) · D-visa', 'situation' => Situation::Freelancer, 'is_eu' => false, 'path' => 'freelancer_gewerbe', 'entry_mode' => 'd_visa'],
            ['key' => 'family-neu', 'label' => 'Family reunification → non-EU sponsor', 'situation' => Situation::FamilyReunification, 'is_eu' => false, 'path' => null, 'entry_mode' => 'd_visa'],
            ['key' => 'family-german', 'label' => 'Family reunification → German sponsor', 'situation' => Situation::FamilyReunification, 'is_eu' => false, 'path' => 'family_reunification_of_german', 'entry_mode' => 'd_visa'],
            ['key' => 'family-eu', 'label' => 'Family reunification → EU sponsor', 'situation' => Situation::FamilyReunification, 'is_eu' => false, 'path' => 'family_reunification_of_eu_citizen', 'entry_mode' => 'visa_free'],
            ['key' => 'nomad-neu', 'label' => 'Digital nomad / other · non-EU', 'situation' => Situation::DigitalNomad, 'is_eu' => false, 'path' => null, 'entry_mode' => 'visa_free'],
            ['key' => 'other-eu', 'label' => 'Still figuring it out (Other) · EU', 'situation' => Situation::Other, 'is_eu' => true, 'path' => null, 'entry_mode' => 'has_permit'],
        ];
    }

    /**
     * The demo roster: the coverage personas plus a few state showcases so the
     * switcher can demonstrate the paused / pre-arrival lanes, not just paths.
     *
     * @return list<array<string, mixed>>
     */
    public static function demo(): array
    {
        $showcases = [
            ['key' => 'planning', 'label' => 'Planning the move (not arrived yet)', 'situation' => Situation::NonEuEmployee, 'is_eu' => false, 'path' => null, 'entry_mode' => 'd_visa', 'planned' => true],
            ['key' => 'temp-housing', 'label' => 'Non-EU employee · temporary housing', 'situation' => Situation::NonEuEmployee, 'is_eu' => false, 'path' => null, 'entry_mode' => 'visa_free', 'housing' => 'temporary'],
            ['key' => 'foreign-licence', 'label' => 'EU employee · foreign driving licence', 'situation' => Situation::EuEmployee, 'is_eu' => true, 'path' => null, 'entry_mode' => 'has_permit', 'license' => 'other'],
        ];

        return [...self::coverage(), ...$showcases];
    }

    /**
     * Build the in-memory User the engine reads for a persona. Never saved.
     *
     * @param  array<string, mixed>  $persona
     */
    public static function userFor(array $persona): User
    {
        $planned = (bool) ($persona['planned'] ?? false);

        $attributes = [
            'entry_mode' => $persona['entry_mode'],
            'housing_status' => $persona['housing'] ?? 'long_term',
        ];
        if (($persona['license'] ?? null) !== null) {
            $attributes['license_country'] = $persona['license'];
        }
        foreach ((array) ($persona['life'] ?? []) as $event) {
            $attributes["{$event}_at"] = now()->subMonth()->toDateString();
        }

        return new User([
            'situation' => $persona['situation']->value,
            'is_eu' => $persona['is_eu'],
            'bureaucracy_path' => $persona['path'],
            // Planning personas carry no arrival date (the "Before you fly" lane).
            'arrival_date' => $planned ? null : now()->subDays(10)->toDateString(),
            'veedel' => 'Altstadt-Nord',
            'profile_attributes' => $attributes,
            'interests' => [],
        ]);
    }

    /**
     * The profile columns to persist onto a REAL user row for this persona —
     * the single source both SeedPersonasCommand (provisioning) and
     * PersonaController (the live "become this persona" QA switcher) write,
     * so the two can never drift. Deliberately narrower than userFor(): it
     * never touches identity columns (name, email, password, onboarded_at),
     * only the situation/profile fields the bureaucracy + profile engines
     * read. profile_attributes also records which persona is currently
     * active, so the frontend switcher can show the current selection.
     *
     * @param  array<string, mixed>  $persona
     * @return array<string, mixed>
     */
    public static function persistableProfile(array $persona): array
    {
        $planned = (bool) ($persona['planned'] ?? false);

        return [
            'situation' => $persona['situation']->value,
            'is_eu' => $persona['is_eu'],
            'bureaucracy_path' => $persona['path'],
            'arrival_date' => $planned ? null : now()->subDays(10)->toDateString(),
            'veedel' => 'Altstadt-Nord',
            'profile_attributes' => [...self::userFor($persona)->profile_attributes, 'qa_persona' => $persona['key']],
        ];
    }
}
