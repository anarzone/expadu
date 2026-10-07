<?php

namespace App\Onboarding;

use App\Bureaucracy\People\EnsureAccountHolder;
use App\Enums\Situation;
use App\Models\BureaucracyCase;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Compatibility input adapter only. It cannot choose a legal path or overwrite history. */
final class ApplyOnboardingAnswers
{
    public function __construct(private EnsureAccountHolder $people, private SaveBureaucracyDraft $drafts, private CompleteBureaucracyOnboarding $complete) {}

    public function execute(User $user, array $validated): BureaucracyCase
    {
        return DB::transaction(function () use ($user, $validated): BureaucracyCase {
            $case = $this->people->dossier($user);
            $answers = $this->answers($validated);
            // A separate active draft may contain newer unfinished input: never overwrite it with a legacy POST.
            $draft = $this->drafts->execute($user, $case->person, (string) Str::uuid(), 0, 1, $answers);
            try {
                $this->complete->execute($user, $case->person, $draft->id, $draft->version, $case->fact_version, (string) Str::uuid());
            } catch (ValidationException $error) {
                throw ValidationException::withMessages(collect($error->errors())->mapWithKeys(function ($messages, $path) {
                    $key = explode('.', $path)[1] ?? $path;

                    $field = match ($key) {
                        'german_level' => 'documented_german_level',
                        'purpose' => 'situation',
                        'citizenship_group' => 'is_eu',
                        default => $key,
                    };

                    return [$field => $messages];
                })->all());
            }

            // Discovery preferences remain separate from the encrypted bureaucracy dossier.
            $profile = Arr::only($validated, ['situation', 'veedel', 'german_level', 'has_deutschlandticket', 'interests']);
            $profile = array_filter($profile, fn ($value) => $value !== null);
            if (isset($profile['veedel']) && in_array($profile['veedel'], collect(config('veedels', []))->flatten()->all(), true)) {
                $profile['city'] = config('bureaucracy_onboarding.legacy_neighbourhood_city');
            }
            $fresh = $user->fresh();
            $fresh->update([...$profile, 'bureaucracy_path' => null]);
            $fresh->setProfileAttribute('qa_persona', null, 'onboarding');
            foreach (['home' => ['Home', '🏠', 0], 'work' => ['Work', '💼', 1]] as $category => [$name, $emoji, $order]) {
                $fresh->places()->firstOrCreate(['category' => $category], ['name' => $name, 'emoji' => $emoji, 'sort_order' => $order]);
            }

            return $case->fresh();
        });
    }

    private function answers(array $input): array
    {
        $answers = [];
        foreach (config('bureaucracy_onboarding.fact_keys') as $key) {
            if ($key === 'german_level') {
                continue; // General language preference is not the separate bureaucracy answer.
            }
            if (array_key_exists($key, $input) && $input[$key] !== null && $input[$key] !== '') {
                $answers[$key] = ['value' => $key === 'arrival_planned' ? (bool) $input[$key] : $input[$key]];
            }
        }
        $situation = isset($input['situation']) ? Situation::from($input['situation']) : null;
        $citizenship = match ($situation) {
            Situation::EuEmployee => 'eu',
            Situation::NonEuEmployee => 'non_eu',
            default => isset($input['is_eu']) ? ((bool) $input['is_eu'] ? 'eu' : 'non_eu') : null,
        };
        if ($citizenship !== null) {
            $answers['citizenship_group'] = ['value' => $citizenship];
        }
        if ($situation !== null) {
            $answers['purpose'] = ['value' => match ($situation) {
                Situation::NonEuEmployee, Situation::EuEmployee => 'employment',
                Situation::Student => 'study', Situation::Freelancer => 'freelance',
                Situation::FamilyReunification => 'family', Situation::DigitalNomad => 'digital_nomad',
                Situation::Other => 'other',
            }];
        }
        if (isset($input['documented_german_level'])) {
            $answers['german_level'] = ['value' => $input['documented_german_level']];
        }

        return $answers;
    }
}
