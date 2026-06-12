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

    public function build(User $user): Profile
    {
        $situation = $user->situation ?? Situation::Other;
        $isEu = $this->resolveIsEu($situation, $user->is_eu);

        return new Profile(
            situation: $situation,
            isEu: $isEu,
            arrivalDate: $user->arrival_date
                ? CarbonImmutable::parse($user->arrival_date)->startOfDay()
                : null,
            veedel: $user->veedel,
            bureaucracyBranch: $this->resolveRefinedBranch($situation, $user->bureaucracy_path),
            ticketAdvice: $this->resolveTicket($situation),
            defaultAreas: $this->resolveAreas($user->veedel),
            germanLevel: $user->german_level,
        );
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
