<?php

namespace App\Onboarding;

use App\Bureaucracy\Facts\CaseFactStore;
use App\Enums\Situation;
use App\Models\BureaucracyCase;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

final class ApplyOnboardingAnswers
{
    public function __construct(private CaseFactStore $factStore) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function execute(User $user, array $validated): BureaucracyCase
    {
        return DB::transaction(function () use ($user, $validated): BureaucracyCase {
            $lockedUser = User::query()
                ->whereKey($user->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $situation = Situation::from($validated['situation']);
            $isEu = $this->isEu($situation, $validated['is_eu'] ?? null);
            $entryMode = $validated['entry_mode'] ?? null;
            $currentResidenceTitle = $validated['current_residence_title'] ?? null;
            $caseGoal = $validated['case_goal'] ?? null;
            $permitTrack = $this->permitTrack($currentResidenceTitle, $caseGoal);
            $planning = (bool) ($validated['arrival_planned'] ?? false);

            $profileValues = Arr::except($validated, [
                'arrival_planned',
                'entry_mode',
                'housing_status',
                'visa_expires_at',
                'current_residence_title',
                'residence_title_expires_at',
                'case_goal',
                'sponsor_current_title',
                'documented_german_level',
                'moved_in_at',
                'address_registration_status',
            ]);

            $lockedUser->update([
                ...$profileValues,
                'is_eu' => $isEu,
                'arrival_date' => $planning ? null : ($validated['arrival_date'] ?? null),
                'city' => 'Köln',
                'onboarded_at' => now(),
            ]);

            $this->storeProfileAttribute($lockedUser, 'entry_mode', $entryMode);
            $this->storeProfileAttribute($lockedUser, 'housing_status', $validated['housing_status'] ?? null);
            $this->storeProfileAttribute(
                $lockedUser,
                'visa_expires_at',
                $entryMode === 'd_visa' ? ($validated['visa_expires_at'] ?? null) : null,
            );
            $this->storeProfileAttribute($lockedUser, 'moved_in_at', $validated['moved_in_at'] ?? null);
            $this->storeProfileAttribute(
                $lockedUser,
                'address_registration_status',
                $validated['address_registration_status'] ?? null,
            );

            $facts = [
                'citizenship_group' => $isEu ? 'eu' : 'non_eu',
                'purpose' => $this->purpose($situation),
                'entry_mode' => $entryMode,
                'visa_expires_at' => $entryMode === 'd_visa' ? ($validated['visa_expires_at'] ?? null) : null,
                'current_residence_title' => $currentResidenceTitle,
                'residence_title_expires_at' => $currentResidenceTitle === null
                    ? null
                    : ($validated['residence_title_expires_at'] ?? null),
                'case_goal' => $caseGoal,
                'sponsor_current_title' => $situation === Situation::FamilyReunification
                    ? ($validated['sponsor_current_title'] ?? null)
                    : null,
                'permit_track' => $permitTrack,
                'german_level' => $validated['documented_german_level'] ?? null,
            ];

            $case = $this->factStore->synchronizeConfirmedFacts(
                $lockedUser,
                $facts,
                'onboarding',
                array_keys(array_filter($facts, fn (mixed $value): bool => $value === null)),
            );

            $this->createRequiredPlaces($lockedUser);

            return $case;
        });
    }

    private function storeProfileAttribute(User $user, string $attribute, mixed $value): void
    {
        $user->setProfileAttribute($attribute, $value, 'onboarding');
    }

    private function createRequiredPlaces(User $user): void
    {
        if (! $user->places()->where('category', 'home')->exists()) {
            $user->places()->create([
                'emoji' => '🏠',
                'name' => 'Home',
                'category' => 'home',
                'sort_order' => 0,
            ]);
        }

        if (! $user->places()->where('category', 'work')->exists()) {
            $user->places()->create([
                'emoji' => '💼',
                'name' => 'Work',
                'category' => 'work',
                'sort_order' => 1,
            ]);
        }
    }

    private function isEu(Situation $situation, mixed $explicit): bool
    {
        return match ($situation) {
            Situation::EuEmployee => true,
            Situation::NonEuEmployee, Situation::FamilyReunification => false,
            default => (bool) $explicit,
        };
    }

    private function purpose(Situation $situation): string
    {
        return match ($situation) {
            Situation::NonEuEmployee, Situation::EuEmployee => 'employment',
            Situation::Student => 'study',
            Situation::Freelancer => 'freelance',
            Situation::FamilyReunification => 'family',
            Situation::DigitalNomad => 'digital_nomad',
            Situation::Other => 'other',
        };
    }

    private function permitTrack(?string $currentResidenceTitle, ?string $caseGoal): ?string
    {
        if ($currentResidenceTitle === 'blue_card' || $caseGoal === 'blue_card') {
            return 'blue_card';
        }

        return $currentResidenceTitle === 'standard_work_permit' ? 'standard' : null;
    }
}
