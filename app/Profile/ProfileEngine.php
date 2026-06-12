<?php

namespace App\Profile;

use App\Enums\Situation;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Deterministic rules engine: raw user attributes → feature configuration.
 * No ML, no I/O — every rule is a match over Situation + is_eu and is
 * unit-tested per branch. This is the pivot the whole app turns on:
 * which bureaucracy branch you see, how deadlines are computed, which
 * ticket advice applies, and which areas content defaults to.
 */
class ProfileEngine
{
    /**
     * Fine-grained path refinements per ambiguous base branch, chosen by
     * the user on the bureaucracy page (stored in users.bureaucracy_path).
     * The first option of each group equals the base branch — picking it
     * still records the choice so the refinement banner collapses.
     *
     * @var array<string, array<string, string>> base branch → path slug → label
     */
    public const PATH_OPTIONS = [
        'non_eu_employee' => [
            'non_eu_employee' => 'Standard work permit',
            'non_eu_employee_blue_card' => 'EU Blue Card',
            'non_eu_employee_chancenkarte' => 'Chancenkarte (job-seeking)',
        ],
        'family_reunification' => [
            'family_reunification' => 'Joining a non-EU citizen',
            'family_reunification_of_german' => 'Joining a German citizen',
            'family_reunification_of_eu_citizen' => 'Joining an EU citizen',
        ],
        'freelancer' => [
            'freelancer' => 'Freelance work (freiberuflich)',
            'freelancer_gewerbe' => 'Trade business (Gewerbe)',
        ],
    ];

    /**
     * Branch shorthand → applies_if AND-group. The importer compiles YAML
     * `situation:` headers through this map, so content stays readable
     * ("student") while the engine only ever evaluates flat predicates.
     *
     * @var array<string, array<string, mixed>>
     */
    public const BRANCH_PREDICATES = [
        'core' => ['purpose' => ['digital_nomad', 'other']],
        'eu_employee' => ['purpose' => 'employment', 'citizenship_group' => 'eu'],
        'non_eu_employee' => ['purpose' => 'employment', 'citizenship_group' => 'non_eu', 'permit_track' => 'standard'],
        'non_eu_employee_blue_card' => ['purpose' => 'employment', 'citizenship_group' => 'non_eu', 'permit_track' => 'blue_card'],
        'non_eu_employee_chancenkarte' => ['purpose' => 'employment', 'citizenship_group' => 'non_eu', 'permit_track' => 'chancenkarte'],
        'student' => ['purpose' => 'study'],
        'freelancer' => ['purpose' => 'freelance', 'business_type' => 'liberal'],
        'freelancer_gewerbe' => ['purpose' => 'freelance', 'business_type' => 'gewerbe'],
        'family_reunification' => ['purpose' => 'family', 'sponsor' => 'non_eu'],
        'family_reunification_of_german' => ['purpose' => 'family', 'sponsor' => 'german'],
        'family_reunification_of_eu_citizen' => ['purpose' => 'family', 'sponsor' => 'eu_citizen'],
    ];

    /**
     * Layer-3 just-in-time questions: a task gated on one of these NULL
     * attributes renders as a teaser card instead of being hidden. One tap
     * answers the attribute and the path recomputes.
     *
     * @var array<string, array{question: string, hint: string, options: list<array{value: string, label: string}>}>
     */
    public const TEASER_QUESTIONS = [
        'license_country' => [
            'question' => "One question: do you have a driving licence you'll use here?",
            'hint' => 'Foreign licences die quietly after 6 months — answering now sets your real deadline (or removes this card forever).',
            'options' => [
                ['value' => 'eu', 'label' => '🇪🇺 EU licence'],
                ['value' => 'other', 'label' => '🌐 Other country'],
                ['value' => 'none', 'label' => "Won't drive"],
            ],
        ],
    ];

    /**
     * Writable long-tail attributes and their allowed values — the teaser /
     * attribute endpoint validates against this whitelist.
     *
     * @var array<string, list<string>>
     */
    public const ATTRIBUTE_VALUES = [
        'entry_mode' => ['d_visa', 'visa_free', 'has_permit'],
        'housing_status' => ['long_term', 'temporary'],
        'license_country' => ['eu', 'other', 'none'],
    ];

    /**
     * Date-valued attributes (life events + move-in). Recording one wakes
     * up the tasks gated on it and anchors their deadlines.
     *
     * @var list<string>
     */
    public const DATE_ATTRIBUTES = ['moved_in_at', 'child_born_at', 'graduated_at', 'found_job_at', 'permit_held_since'];

    /**
     * Known life-event names a task's trigger_event may reference.
     *
     * @var list<string>
     */
    public const LIFE_EVENTS = ['child_born', 'graduated', 'found_job'];

    public function build(User $user): Profile
    {
        $situation = $user->situation ?? Situation::Other;
        $isEu = $this->resolveIsEu($situation, $user->is_eu);
        $branch = $this->resolveRefinedBranch($situation, $user->bureaucracy_path);

        return new Profile(
            situation: $situation,
            isEu: $isEu,
            arrivalDate: $user->arrival_date
                ? CarbonImmutable::parse($user->arrival_date)->startOfDay()
                : null,
            veedel: $user->veedel,
            bureaucracyBranch: $branch,
            ticketAdvice: $this->resolveTicket($situation),
            defaultAreas: $this->resolveAreas($user->veedel),
            germanLevel: $user->german_level,
            attributes: $this->resolveAttributes($user, $situation, $isEu, $branch),
        );
    }

    /**
     * The flat attribute bag every applies_if is evaluated against. NULL
     * means "not asked yet" — the evaluator treats it as unknown, which
     * renders gated tasks as teasers instead of hiding them.
     *
     * @return array<string, mixed>
     */
    private function resolveAttributes(User $user, Situation $situation, bool $isEu, string $branch): array
    {
        $stored = $user->profile_attributes ?? [];

        $purpose = match ($situation) {
            Situation::NonEuEmployee, Situation::EuEmployee => 'employment',
            Situation::Student => 'study',
            Situation::Freelancer => 'freelance',
            Situation::FamilyReunification => 'family',
            Situation::DigitalNomad => 'digital_nomad',
            Situation::Other => 'other',
        };

        // Refinements default to the base path: showing the standard track
        // until the user sharpens it mirrors the path-banner behaviour.
        $permitTrack = match ($branch) {
            'non_eu_employee_blue_card' => 'blue_card',
            'non_eu_employee_chancenkarte' => 'chancenkarte',
            default => 'standard',
        };
        $sponsor = match ($branch) {
            'family_reunification_of_german' => 'german',
            'family_reunification_of_eu_citizen' => 'eu_citizen',
            default => 'non_eu',
        };
        $businessType = $branch === 'freelancer_gewerbe' ? 'gewerbe' : 'liberal';

        $housing = $stored['housing_status'] ?? 'long_term';
        // The Anmeldung clock anchors to move-in: explicit date, else
        // arrival for long-term housing, else null (= paused).
        $movedInAt = $stored['moved_in_at']
            ?? ($housing === 'long_term' ? $user->arrival_date?->toDateString() : null);

        return [
            'citizenship_group' => $isEu ? 'eu' : 'non_eu',
            'purpose' => $purpose,
            'permit_track' => $permitTrack,
            'sponsor' => $sponsor,
            'business_type' => $businessType,
            // Legacy users answered before the question existed — visa_free
            // preserves the old behaviour (permit tasks visible, 90-day clock).
            'entry_mode' => $stored['entry_mode'] ?? 'visa_free',
            'housing_status' => $housing,
            'moved_in_at' => $movedInAt,
            'license_country' => $stored['license_country'] ?? null,
            'child_born_at' => $stored['child_born_at'] ?? null,
            'graduated_at' => $stored['graduated_at'] ?? null,
            'found_job_at' => $stored['found_job_at'] ?? null,
            'permit_held_since' => $stored['permit_held_since'] ?? null,
            'arrival_date' => $user->arrival_date?->toDateString(),
            'veedel' => $user->veedel,
        ];
    }

    /**
     * EU citizenship is implied by some situations and asked explicitly
     * for the ambiguous ones. Unknown defaults to non-EU: the non-EU
     * path is a superset, and showing an EU citizen a removable extra
     * step is far cheaper than hiding a residence permit from someone
     * who needs one.
     */
    private function resolveIsEu(Situation $situation, ?bool $explicit): bool
    {
        return match ($situation) {
            Situation::EuEmployee => true,
            Situation::NonEuEmployee, Situation::FamilyReunification => false,
            default => $explicit ?? false,
        };
    }

    /**
     * Refinement options applicable to this user's base branch — empty for
     * unambiguous branches (eu_employee, student, core).
     *
     * @return array<string, string> path slug → label
     */
    public function pathOptionsFor(User $user): array
    {
        $base = $this->resolveBranch($user->situation ?? Situation::Other);

        return self::PATH_OPTIONS[$base] ?? [];
    }

    /**
     * The branch the bureaucracy page reads: a user-chosen refinement when
     * one is stored and still valid for the situation, else the base branch.
     */
    private function resolveRefinedBranch(Situation $situation, ?string $path): string
    {
        $base = $this->resolveBranch($situation);

        if ($path !== null && isset(self::PATH_OPTIONS[$base][$path])) {
            return $path;
        }

        return $base;
    }

    private function resolveBranch(Situation $situation): string
    {
        return match ($situation) {
            Situation::NonEuEmployee => 'non_eu_employee',
            Situation::EuEmployee => 'eu_employee',
            Situation::Student => 'student',
            Situation::Freelancer => 'freelancer',
            Situation::FamilyReunification => 'family_reunification',
            Situation::DigitalNomad, Situation::Other => 'core',
        };
    }

    private function resolveTicket(Situation $situation): TicketAdvice
    {
        return match ($situation) {
            Situation::Student => TicketAdvice::SemesterTicket,
            Situation::NonEuEmployee, Situation::EuEmployee => TicketAdvice::JobTicketAsk,
            default => TicketAdvice::DeutschlandTicket,
        };
    }

    /**
     * @return list<string>
     */
    private function resolveAreas(?string $veedel): array
    {
        if ($veedel === null) {
            return [];
        }

        foreach (config('veedels', []) as $stadtteile) {
            if (in_array($veedel, $stadtteile, true)) {
                return array_values($stadtteile);
            }
        }

        return [$veedel];
    }
}
