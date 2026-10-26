<?php

namespace App\Privacy\Export;

use App\Bureaucracy\People\PersonDataLifecycle;
use App\Models\BureaucracyPerson;
use App\Models\User;
use App\Personalisation\Pseudonym;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Everything Expadu keeps about one person, for "Download your data": a
 * JSON file per area and a README naming each. Only rows linked to this
 * account (its id, its Paperwork people, its pseudonym); secrets (the
 * password hash, two-factor and push keys, remember tokens) are left out.
 * System and short-lived data (debug logs, throttles, caches) isn't kept
 * about the person as such and isn't included.
 */
final class AccountData
{
    /** Columns never exported, from any table. */
    private const SECRET = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'public_key', 'auth_token'];

    public function __construct(private PersonDataLifecycle $paperwork) {}

    /**
     * @return array<string, string> file name => contents
     */
    public function files(User $user): array
    {
        $areas = [
            'account.json' => ['Your account and situation profile, and changes to it.', fn () => $this->account($user)],
            'settings.json' => ['Your settings, notification choices, needs (step-free) and push devices.', fn () => $this->settings($user)],
            'consents.json' => ['What you agreed Expadu may process, and when.', fn () => $this->consents($user)],
            'paperwork.json' => ['Your Paperwork record for each person you manage, and your Paperwork tasks.', fn () => $this->paperwork($user)],
            'composer.json' => ['Composer: your conversations, your plans (My Plans) and the changes you can undo.', fn () => $this->composer($user)],
            'places.json' => ['Places you saved, have been to or rated, your reviews and work answers.', fn () => $this->places($user)],
            'events.json' => ['Events you saved, set reminders for, joined or organised.', fn () => $this->events($user)],
            'travel.json' => ['Your places (Home, Work), favourite stops, saved and recent journeys, planned and active trips.', fn () => $this->travel($user)],
            'alerts.json' => ['Alerts and notifications sent to you, and what you muted.', fn () => $this->alerts($user)],
            'activity.json' => ['What you did in the app that Expadu recorded (opened, saved, skipped).', fn () => $this->activity($user)],
            'personalisation.json' => ['What personalised suggestions learned, kept under a pseudonym.', fn () => $this->personalisation($user)],
            'location.json' => ['Locations kept for the last 7 days, when location sharing is on.', fn () => $this->location($user)],
        ];

        $files = [];
        $readme = [
            'Your data from Expadu',
            'Exported '.now()->toIso8601String().' for '.$user->email.'.',
            '',
            'One JSON file per area:',
        ];
        foreach ($areas as $name => [$about, $read]) {
            $files[$name] = json_encode(
                ['exported_at' => now()->toIso8601String(), 'data' => $read()],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
            );
            $readme[] = "- {$name}: {$about}";
        }
        $readme[] = '';
        $readme[] = 'Dates and times are ISO 8601, with their offset from UTC.';

        return ['README.txt' => implode("\n", $readme)."\n"] + $files;
    }

    /** @return array<string, mixed> */
    private function account(User $user): array
    {
        return [
            'user' => $this->clean((array) DB::table('users')->where('id', $user->id)->first()),
            'profile_changes' => $this->rows('profile_attribute_changes', 'user_id', $user->id),
            'sessions' => array_map(fn (array $s) => array_intersect_key($s, array_flip(['ip_address', 'user_agent', 'last_activity'])),
                $this->rows('sessions', 'user_id', $user->id)),
        ];
    }

    /** @return array<string, mixed> */
    private function settings(User $user): array
    {
        return [
            'settings' => $this->json(DB::table('user_settings')->where('user_id', $user->id)->value('settings')),
            'needs' => ['step_free' => (bool) $user->needs_step_free],
            'notification_preferences' => $this->rows('notification_preferences', 'user_id', $user->id),
            'push_devices' => $this->morph('push_subscriptions', 'subscribable', $user),
        ];
    }

    /** @return array<string, mixed> */
    private function consents(User $user): array
    {
        return [
            'processing' => $this->rows('bureaucracy_processing_consents', 'actor_id', $user->id),
            'legacy_processing' => $this->rows('bureaucracy_processing_legacy_usage', 'actor_id', $user->id),
        ];
    }

    /** @return array<string, mixed> */
    private function paperwork(User $user): array
    {
        // Their own record, and the people in the Paperwork they run.
        $people = BureaucracyPerson::query()
            ->whereNull('erased_at')
            ->where(fn ($q) => $q->where('account_user_id', $user->id)
                ->orWhereHas('workspaces', fn ($w) => $w->where('owner_user_id', $user->id)))
            ->orderBy('id')
            ->get();

        $records = [];
        foreach ($people as $person) {
            try {
                $records[] = $this->paperwork->export($user, $person);
            } catch (AuthorizationException) {
                // Not theirs to see.
            }
        }

        return ['people' => $records, 'tasks' => $this->rows('user_tasks', 'user_id', $user->id)];
    }

    /** @return array<string, mixed> */
    private function composer(User $user): array
    {
        $state = $this->json(DB::table('composer_workspaces')->where('user_id', $user->id)->value('state')) ?? [];

        return [
            'conversations' => $state['conversations'] ?? [],
            'plans' => $state['plans'] ?? [],
            'changes' => $state['changes'] ?? [],
            'workspace' => array_diff_key($state, array_flip(['conversations', 'plans', 'changes'])),
            'ranked_suggestions' => $this->rows('composer_runs', 'user_id', $user->id),
        ];
    }

    /** @return array<string, mixed> */
    private function places(User $user): array
    {
        return [
            'saved_been_rated' => $this->withNames('spot_feedback', $user->id),
            'reviews' => $this->withNames('reviews', $user->id),
            'work_answers' => $this->withNames('place_work_answers', $user->id),
        ];
    }

    /** @return array<string, mixed> */
    private function events(User $user): array
    {
        return [
            'saved' => $this->rows('event_bookmarks', 'user_id', $user->id),
            'reminders' => $this->rows('event_reminders', 'user_id', $user->id),
            'joined' => $this->rows('event_attendees', 'user_id', $user->id),
            'organised' => $this->rows('events', 'organiser_id', $user->id),
        ];
    }

    /** @return array<string, mixed> */
    private function travel(User $user): array
    {
        return [
            'places' => $this->rows('user_places', 'user_id', $user->id),
            'favourite_stops' => $this->rows('favourite_stops', 'user_id', $user->id),
            'saved_journeys' => $this->rows('saved_journeys', 'user_id', $user->id),
            'recent_journeys' => $this->rows('journey_recents', 'user_id', $user->id),
            'planned_trips' => $this->rows('planned_trips', 'user_id', $user->id),
            'active_trip' => $this->rows('active_trips', 'user_id', $user->id),
        ];
    }

    /** @return array<string, mixed> */
    private function alerts(User $user): array
    {
        $muted = [];
        try {
            $prefix = (string) config('database.redis.options.prefix', '');
            foreach (Redis::connection()->keys("mute:{$user->id}:*") as $key) {
                $muted[] = substr((string) $key, strlen($prefix));
            }
        } catch (Throwable) {
            // Redis unreachable: nothing muted can be read.
        }

        return [
            'alerts' => $this->rows('alerts', 'user_id', $user->id),
            'notifications' => $this->morph('notifications', 'notifiable', $user),
            'muted' => $muted,
        ];
    }

    /** @return array<string, mixed> */
    private function activity(User $user): array
    {
        return ['events' => $this->rows('user_events', 'user_id', $user->id)];
    }

    /** @return array<string, mixed> */
    private function personalisation(User $user): array
    {
        $subject = Pseudonym::for($user);

        return [
            'taste_profile' => $this->rows('taste_profiles', 'subject_key', $subject),
            'signals' => $this->rows('user_signals', 'subject_key', $subject),
            'affinities' => $this->rows('user_affinities', 'subject_key', $subject),
            'hidden_places' => $this->rows('user_place_negatives', 'subject_key', $subject),
        ];
    }

    /** @return array<string, mixed> */
    private function location(User $user): array
    {
        try {
            $redis = Redis::connection();
            // Oldest first; each point is a JSON fix with its own time.
            $history = $redis->zrange("location_history:{$user->id}", 0, -1) ?: [];

            return [
                'history' => array_map(fn ($point) => $this->json((string) $point) ?? $point, $history),
                'confirmed' => $this->json($redis->get("confirmed_location:{$user->id}")),
                'neighbourhood' => $this->json($redis->get("veedel_anchor:{$user->id}")),
            ];
        } catch (Throwable) {
            return ['history' => [], 'note' => 'Location history couldn’t be read when this file was made.'];
        }
    }

    /**
     * A table's rows for this person, secrets left out; none when the table
     * doesn't exist on this install.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(string $table, string $column, int|string $value): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return [];
        }

        return DB::table($table)->where($column, $value)->orderBy($column)->get()
            ->map(fn ($row) => $this->clean((array) $row))->all();
    }

    /** @return list<array<string, mixed>> */
    private function morph(string $table, string $name, User $user): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)->where("{$name}_type", $user->getMorphClass())->where("{$name}_id", $user->id)->get()
            ->map(fn ($row) => $this->clean((array) $row))->all();
    }

    /**
     * Rows about places, each with the place's name.
     *
     * @return list<array<string, mixed>>
     */
    private function withNames(string $table, int $user): array
    {
        $names = fn (array $rows) => DB::table('spots')->whereIn('id', array_filter(array_column($rows, 'spot_id')))->pluck('name', 'id');
        $rows = $this->rows($table, 'user_id', $user);
        if ($rows === [] || ! array_key_exists('spot_id', $rows[0])) {
            return $rows;
        }
        $named = $names($rows);

        return array_map(fn (array $row) => ['place' => $named[$row['spot_id']] ?? null] + $row, $rows);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function clean(array $row): array
    {
        $row = array_diff_key($row, array_flip(self::SECRET));

        return array_map(fn ($value) => is_string($value) && str_starts_with(ltrim($value), '{') || is_string($value) && str_starts_with(ltrim($value), '[')
            ? ($this->json($value) ?? $value)
            : $value, $row);
    }

    private function json(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }
}
