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
            bureaucracyBranch: $this->resolveBranch($situation),
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
