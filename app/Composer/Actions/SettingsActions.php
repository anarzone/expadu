<?php

namespace App\Composer\Actions;

use App\Accessibility\SetStepFreeNeed;
use App\Concerns\ProfileValidationRules;
use App\Enums\Situation;
use App\Models\NotificationPreference;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/**
 * Their settings, as Settings keeps them: a kind of notification on or off (as Settings ›
 * Notifications saves it), their step-free need (SetStepFreeNeed, its one writer), and their
 * profile's name, city, situation or German level, checked by the profile's own rules.
 * Email stays out: changing it needs confirming. Push on this
 * device needs a tap in the browser, so that one is opened, never set (open_in_app). What
 * a value is stays out of what the AI is told: the card says it.
 */
final class SettingsActions implements ComposerAction
{
    use ProfileValidationRules;

    /** The kinds Settings › Notifications shows, as it names them. */
    public const NOTIFICATIONS = ['transit' => 'Transit disruptions', 'checklist' => 'Paperwork deadlines', 'events' => 'Event reminders',
        'weather' => 'Weather alerts', 'rhine' => 'Rhine flood alerts', 'brief' => 'Morning and evening briefs'];

    /** The profile fields Composer may change, as the profile names them. */
    public const PROFILE = ['name' => 'Name', 'city' => 'City', 'situation' => 'Situation', 'german_level' => 'German level'];

    private const SITUATIONS = ['non_eu_employee' => 'Non-EU employee', 'eu_employee' => 'EU employee', 'student' => 'Student', 'freelancer' => 'Freelancer',
        'family_reunification' => 'Family reunification', 'digital_nomad' => 'Digital nomad', 'other' => 'Other'];

    private const GERMAN = ['none' => 'None yet', 'a1' => 'A1 — Beginner', 'a2' => 'A2 — Elementary', 'b1' => 'B1 — Intermediate',
        'b2' => 'B2 — Upper-intermediate', 'c1' => 'C1 — Advanced', 'c2' => 'C2 — Fluent'];

    public function prepare(User $user, string $action, array $input, CarbonImmutable $now): array
    {
        return match ($action) {
            'set_notifications' => $this->prepareNotifications($user, $input),
            'set_needs' => $this->prepareNeeds($user, $input),
            default => $this->prepareProfile($user, $input),
        };
    }

    public function run(User $user, string $action, array $prepared, CarbonImmutable $now): array
    {
        return match ($action) {
            'set_notifications' => $this->notify($user, $prepared['kind'], $prepared['on'])
                + ['undo' => ['kind' => $prepared['kind'], 'on' => ! $prepared['on']]],
            'set_needs' => $this->stepFree($user, $prepared['on']) + ['undo' => ['on' => ! $prepared['on']]],
            default => $this->profile($user, $prepared['field'], $prepared['value'])
                + ['undo' => ['field' => $prepared['field'], 'value' => $prepared['previous']]],
        };
    }

    public function undo(User $user, string $action, array $undo, CarbonImmutable $now): array
    {
        $done = match ($action) {
            'set_notifications' => $this->notify($user, $undo['kind'], $undo['on']),
            'set_needs' => $this->stepFree($user, $undo['on']),
            default => $this->profile($user, $undo['field'], $undo['value']),
        };

        return ['title' => "Undone. {$done['title']}."];
    }

    /** @return array<string, mixed> */
    private function prepareNotifications(User $user, array $input): array
    {
        $kind = is_string($input['kind'] ?? null) && isset(self::NOTIFICATIONS[$input['kind']]) ? $input['kind'] : null;
        if ($kind === null) {
            throw new ActionRefused('Say which notifications: '.implode(', ', array_keys(self::NOTIFICATIONS)).'.');
        }
        $on = ($input['on'] ?? true) !== false;
        if ($user->wantsNotification($kind) === $on) {
            throw new ActionRefused(self::NOTIFICATIONS[$kind].' are '.($on ? 'on' : 'off').' already.');
        }

        // Paperwork is the section's own name.
        $name = $kind === 'checklist' ? self::NOTIFICATIONS[$kind] : mb_strtolower(self::NOTIFICATIONS[$kind]);

        return ['kind' => $kind, 'on' => $on, 'propose' => 'Turn '.($on ? 'on ' : 'off ').$name.'?'];
    }

    /** @return array<string, mixed> */
    private function prepareNeeds(User $user, array $input): array
    {
        if (! is_bool($input['step_free'] ?? null)) {
            throw new ActionRefused('Say whether they need step-free ways.');
        }
        if ((bool) $user->needs_step_free === $input['step_free']) {
            throw new ActionRefused($input['step_free'] ? 'Step-free ways are on already.' : 'Step-free ways are off already.');
        }

        return ['on' => $input['step_free'], 'propose' => $input['step_free'] ? 'Plan only step-free ways for you?' : 'Stop planning only step-free ways?'];
    }

    /** @return array<string, mixed> */
    private function prepareProfile(User $user, array $input): array
    {
        $field = is_string($input['field'] ?? null) && isset(self::PROFILE[$input['field']]) ? $input['field'] : null;
        if ($field === null) {
            throw new ActionRefused(($input['field'] ?? null) === 'email' ? 'Changing their email needs confirming it, so it’s done in Settings › Your account.'
                : 'Composer can change their name, city, situation or German level.');
        }
        $value = is_string($input['value'] ?? null) ? trim($input['value']) : null;
        // A situation or level said in words ("B2", "student") is its value.
        $value = match ($field) {
            'situation' => self::valueOf(self::SITUATIONS, $value),
            'german_level' => self::valueOf(self::GERMAN, $value),
            default => $value === '' ? null : $value,
        };
        $rules = $this->profileRules($user->id);
        // Said in Composer, a field is set, never emptied: required, as the profile's own rules otherwise check it.
        $check = Validator::make([$field => $value], [$field => ['required', ...array_filter($rules[$field], fn (mixed $rule): bool => ! in_array($rule, ['sometimes', 'nullable', 'required'], true))]]);
        if ($check->fails()) {
            throw new ActionRefused((string) $check->errors()->first());
        }
        $previous = $user->{$field} instanceof \BackedEnum ? $user->{$field}->value : $user->{$field};
        if ($previous === $value) {
            throw new ActionRefused(self::PROFILE[$field].' is that already.');
        }

        return ['field' => $field, 'value' => $value, 'previous' => $previous,
            'propose' => 'Change your '.mb_strtolower(self::PROFILE[$field]).' to '.self::shown($field, $value).'?',
            'tell' => 'Change their '.mb_strtolower(self::PROFILE[$field]).' to what they said?'];
    }

    /** @return array{title: string, link: array<string, string>, said: string} */
    private function notify(User $user, string $kind, bool $on): array
    {
        $preferences = [...($user->notificationPreference?->preferences ?? NotificationPreference::defaults()), $kind => $on];
        $user->notificationPreference()->updateOrCreate(['user_id' => $user->id], ['preferences' => $preferences]);
        $user->unsetRelation('notificationPreference');
        $title = self::NOTIFICATIONS[$kind].' are '.($on ? 'on' : 'off');

        return ['title' => $title, 'link' => ['label' => 'See notifications →', 'href' => '/settings/notifications'], 'said' => "{$title}."];
    }

    /** @return array{title: string, link: array<string, string>, said: string} */
    private function stepFree(User $user, bool $on): array
    {
        app(SetStepFreeNeed::class)->execute($user, $on);
        $title = $on ? 'Travel and Composer plan step-free ways for you' : 'Ways aren’t limited to step-free any more';

        return ['title' => $title, 'link' => ['label' => 'See it in Travel →', 'href' => '/travel'], 'said' => "{$title}."];
    }

    /** @return array{title: string, link: array<string, string>, said: string} */
    private function profile(User $user, string $field, ?string $value): array
    {
        $user->fill([$field => $value])->save();
        $label = self::PROFILE[$field];

        return ['title' => $value === null ? "{$label} is empty" : "{$label}: ".self::shown($field, $value),
            'link' => ['label' => 'See your profile →', 'href' => $field === 'name' ? '/settings/account' : '/profile'],
            // What it is now stays out of what the AI is told.
            'said' => "Their {$label} is changed as they said."];
    }

    /** The value a word means: its key ("b2"), its label ("B2 — Upper-intermediate") or the label's first word ("B2", "Student"). */
    private static function valueOf(array $labels, ?string $said): ?string
    {
        $said = mb_strtolower(trim((string) $said));
        foreach ($labels as $key => $label) {
            $words = mb_strtolower($label);
            if ($said === $key || $said === $words || $said === str_replace('_', ' ', $key) || $said === trim(explode('—', $words)[0])) {
                return $key;
            }
        }

        return $said === '' ? null : $said;
    }

    private static function shown(string $field, ?string $value): string
    {
        return match ($field) {
            'situation' => self::SITUATIONS[$value] ?? (string) $value,
            'german_level' => self::GERMAN[$value] ?? (string) $value,
            default => (string) $value,
        };
    }
}
