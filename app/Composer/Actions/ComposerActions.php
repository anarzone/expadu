<?php

namespace App\Composer\Actions;

use App\Composer\Assistant\Ask;
use App\Composer\WorkspaceStore;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Composer does things (owner, 23 Oct 2026: "composer should be able to do anything in the
 * app"): a change the AI asks for is made, with Undo for 24 hours, or proposed with Apply
 * when the person asks first (their setting, per kind of change). Each change goes through
 * the app's own code for it; its card (`changed`) says what it did in Composer's words,
 * from the records it changed. Permanent changes are never made here (InAppPlaces).
 */
final class ComposerActions
{
    /** Each change, the code that makes it, and whose Ask first it follows. */
    public const ACTIONS = [
        'place_feedback' => [PlaceFeedbackAction::class, 'everyday'],
        'set_my_place' => [MyPlaceAction::class, 'everyday'],
        'add_to_day' => [DayActions::class, 'everyday'],
        'remove_from_day' => [DayActions::class, 'everyday'],
        'swap_in_day' => [DayActions::class, 'everyday'],
        'move_in_day' => [DayActions::class, 'everyday'],
        'clear_day' => [DayActions::class, 'everyday'],
        'save_event' => [EventActions::class, 'everyday'],
        'remind_me' => [EventActions::class, 'everyday'],
        'save_stop' => [TravelActions::class, 'everyday'],
        'set_travel_prefs' => [TravelActions::class, 'everyday'],
        'plan_trip_for_later' => [TravelActions::class, 'everyday'],
        'mute_alerts' => [AlertActions::class, 'everyday'],
        'dismiss_alert' => [AlertActions::class, 'everyday'],
        'set_notifications' => [SettingsActions::class, 'everyday'],
        'set_needs' => [SettingsActions::class, 'everyday'],
        'update_profile' => [SettingsActions::class, 'everyday'],
        'change_paperwork_fact' => [PaperworkActions::class, 'paperwork'],
        'paperwork_step' => [PaperworkActions::class, 'paperwork'],
        'paperwork_report' => [PaperworkActions::class, 'paperwork'],
    ];

    /**
     * Always proposed: Paperwork takes an answer read from someone's words only once they
     * confirm it (FactInputMethod::ConfirmedExtraction), so Apply is that confirmation.
     */
    public const ALWAYS_ASKS = ['change_paperwork_fact'];

    /** How long a change can be undone, and a proposal applied (owner, 23 Oct 2026). */
    public const HOURS = 24;

    /** Refused Apply, Undo or edit: why, in words the bar shows. */
    public const EXPIRED = 'This suggestion expired. Please ask again.';

    public const TOO_LATE = 'This change can no longer be undone here. Change it back where it’s kept.';

    public function __construct(private WorkspaceStore $workspace) {}

    /**
     * A change the AI asked for: made, or proposed when they ask first.
     *
     * @param  array<string, mixed>  $input  the tool's input
     * @return array{said: string, blocks: list<array<string, mixed>>} what the AI reads, and the cards
     */
    public function request(User $user, string $action, array $input, CarbonImmutable $now, bool $manual = false, bool $propose = false): array
    {
        [$class, $domain] = self::ACTIONS[$action] ?? [null, null];
        if ($class === null) {
            return ['said' => 'Not done: Composer can’t do that.', 'blocks' => []];
        }
        try {
            // Typed into a row by the person themselves, an answer is theirs as given (a manual answer).
            $prepared = [...app($class)->prepare($user, $action, $input, $now), 'manual' => $manual];
            $record = ['token' => (string) Str::uuid(), 'action' => $action, 'domain' => $domain, 'prepared' => $prepared, 'undo' => null];
            // A suggestion they may add (suggest_for_day) is always a proposal: nothing changes until they tap it.
            if ($propose || (! $manual && in_array($action, self::ALWAYS_ASKS, true)) || $this->asksFirst($user, $domain)) {
                $record = $this->kept($user, [...$record, 'state' => 'proposed', 'expires_at' => $now->addHours(self::HOURS)->toIso8601String()],
                    ['title' => $prepared['propose'], ...$prepared]);

                return ['said' => 'Proposed, not done yet: '.($prepared['tell'] ?? $prepared['propose']).(isset($prepared['about']) ? " ({$prepared['about']})" : '')
                    ." It's done only when they tap Apply on the card.", 'blocks' => $record['blocks']];
            }
            $record = $this->made($user, $record, $now);

            return ['said' => "Done: {$record['said']} They can undo it on the card.", 'blocks' => $record['blocks']];
        } catch (ActionRefused $refused) {
            return ['said' => 'Not done: '.$refused->getMessage(), 'blocks' => []];
        }
    }

    /**
     * Apply on a proposal: made as it was proposed. A proposal past its time, or whose
     * record changed meanwhile, is refused and stays as it is.
     *
     * @return array{workspace: array<string, mixed>, refusal: ?string}
     */
    public function apply(User $user, string $token, CarbonImmutable $now): array
    {
        $record = $this->workspace->action($user, $token) ?? abort(404);
        if ($record['state'] !== 'proposed') {
            return ['workspace' => $this->workspace->read($user), 'refusal' => null];
        }
        if (CarbonImmutable::parse($record['expires_at'])->isPast()) {
            return ['workspace' => $this->workspace->read($user), 'refusal' => self::EXPIRED];
        }
        try {
            $record = $this->made($user, $record, $now);
        } catch (ActionRefused $refused) {
            return ['workspace' => $this->workspace->read($user), 'refusal' => $refused->getMessage()];
        }

        return ['workspace' => $record['workspace'], 'refusal' => null];
    }

    /**
     * Undo on a change made in the last 24 hours, through the app's own way back.
     *
     * @return array{workspace: array<string, mixed>, refusal: ?string}
     */
    public function undo(User $user, string $token, CarbonImmutable $now): array
    {
        $record = $this->workspace->action($user, $token) ?? abort(404);
        if ($record['state'] !== 'done') {
            return ['workspace' => $this->workspace->read($user), 'refusal' => null];
        }
        if (CarbonImmutable::parse($record['expires_at'])->isPast()) {
            return ['workspace' => $this->workspace->read($user), 'refusal' => self::TOO_LATE];
        }
        try {
            $undone = app(self::ACTIONS[$record['action']][0])->undo($user, $record['action'], $record['undo'], $now);
        } catch (ActionRefused $refused) {
            return ['workspace' => $this->workspace->read($user), 'refusal' => $refused->getMessage()];
        }
        $record = $this->kept($user, [...$record, 'state' => 'undone'], $undone);

        return ['workspace' => $record['workspace'], 'refusal' => null];
    }

    /**
     * A change the person made on a card themselves (a record row's new date): made, or
     * proposed when they ask first, in a reply of Composer's own after their conversation.
     *
     * @param  array<string, mixed>  $input
     * @return array{workspace: array<string, mixed>, refusal: ?string}
     */
    public function act(User $user, string $action, array $input, CarbonImmutable $now): array
    {
        $made = $this->request($user, $action, $input, $now, manual: true);
        if ($made['blocks'] === []) {
            return ['workspace' => $this->workspace->read($user), 'refusal' => Str::after($made['said'], 'Not done: ')];
        }
        $workspace = $this->workspace->read($user);

        return ['workspace' => $this->workspace->answer($user, (string) $workspace['active'], Ask::message('', $made['blocks'], ['written' => 'code']), null), 'refusal' => null];
    }

    /** Whether they asked to be asked first for this kind of change (Settings › Composer). */
    public function asksFirst(User $user, string $domain): bool
    {
        return (bool) $user->setting("composer_ask_first_{$domain}");
    }

    /**
     * Makes a prepared change and keeps what undoes it.
     *
     * @return array<string, mixed> the record, with its cards
     *
     * @throws ActionRefused
     */
    private function made(User $user, array $record, CarbonImmutable $now): array
    {
        $done = app(self::ACTIONS[$record['action']][0])->run($user, $record['action'], $record['prepared'], $now);

        return $this->kept($user, [...$record, 'state' => 'done', 'undo' => $done['undo'], 'said' => $done['said'],
            'expires_at' => $now->addHours(self::HOURS)->toIso8601String()], $done);
    }

    /**
     * Keeps the record with its cards as they are now, wherever an answer shows them (session
     * 2's blocks, 23 Oct 2026): a proposal with Apply; done with Undo until it expires, and
     * what changed in their Paperwork plan; undone, its line alone.
     *
     * @param  array<string, mixed>  $fields  the line (`title`) and what else the change gave
     * @return array<string, mixed>
     */
    private function kept(User $user, array $record, array $fields): array
    {
        $token = $record['token'];
        $line = $fields['title'].(str_ends_with($fields['title'], '?') || str_ends_with($fields['title'], '.') ? '' : '.');
        $date = ($record['prepared']['key'] ?? null) !== null && ($record['prepared']['date'] ?? false) === true;
        $record['blocks'] = match ($record['state']) {
            'proposed' => [array_filter(['type' => 'proposal', 'id' => $token, 'token' => $token, 'area' => $record['domain'], 'action' => $record['action'],
                'title' => $fields['title'], 'from' => $fields['from'] ?? null, 'to' => $fields['to'] ?? null, 'sub' => $fields['sub'] ?? null,
                'fact_key' => $record['prepared']['key'] ?? null,
                // "Change the date" opens a date field on the proposal itself.
                'edit' => $date ? ['kind' => 'date', 'value' => $record['prepared']['value']] : null,
                'until' => $record['expires_at'],
                // Composer's own words, said when the AI said nothing.
                'text' => "{$line} Tap Apply to make the change."], fn ($v) => $v !== null)],
            'done' => array_values(array_filter([
                array_filter(['type' => 'done', 'id' => $token, 'area' => $record['domain'], 'action' => $record['action'], 'text' => $line,
                    'sub' => $fields['sub'] ?? null, 'undo' => ['token' => $token, 'until' => $record['expires_at']],
                    'link' => $fields['link'] ?? null, 'day' => $fields['day'] ?? null, 'ask' => $fields['ask'] ?? null], fn ($v) => $v !== null),
                ($fields['changes'] ?? []) === [] ? null : ['type' => 'plan_changes', 'id' => "{$token}:plan", 'area' => $record['domain'],
                    'changes' => $fields['changes'], 'link' => ['label' => 'Open Paperwork', 'href' => '/bureaucracy']],
            ])),
            default => [['type' => 'undone', 'id' => $token, 'area' => $record['domain'], 'action' => $record['action'], 'text' => $line]],
        };
        $record['workspace'] = $this->workspace->keepAction($user, array_diff_key($record, ['workspace' => true]));

        return $record;
    }
}
