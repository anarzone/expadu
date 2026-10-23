<?php

namespace App\Composer\Actions;

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
        'clear_day' => [DayActions::class, 'everyday'],
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

    /** What a card may show besides its line, as the change gave it. */
    private const SHOWN = ['sub', 'from', 'to', 'plan', 'day', 'ask', 'link'];

    public function __construct(private WorkspaceStore $workspace) {}

    /**
     * A change the AI asked for: made, or proposed when they ask first.
     *
     * @param  array<string, mixed>  $input  the tool's input
     * @return array{said: string, block: ?array<string, mixed>} what the AI reads, and the card
     */
    public function request(User $user, string $action, array $input, CarbonImmutable $now): array
    {
        [$class, $domain] = self::ACTIONS[$action] ?? [null, null];
        if ($class === null) {
            return ['said' => 'Not done: Composer can’t do that.', 'block' => null];
        }
        try {
            $prepared = app($class)->prepare($user, $action, $input, $now);
            $record = ['token' => (string) Str::uuid(), 'action' => $action, 'domain' => $domain, 'prepared' => $prepared, 'undo' => null];
            if (in_array($action, self::ALWAYS_ASKS, true) || $this->asksFirst($user, $domain)) {
                $record = $this->kept($user, [...$record, 'state' => 'proposed', 'expires_at' => $now->addHours(self::HOURS)->toIso8601String()],
                    ['title' => $prepared['propose'], ...array_intersect_key($prepared, array_flip(self::SHOWN))]);

                return ['said' => "Proposed, not done yet: {$prepared['propose']}".(isset($prepared['about']) ? " ({$prepared['about']})" : '')
                    ." It's done only when they tap Apply on the card.", 'block' => $record['block']];
            }
            $record = $this->made($user, $record, $now);

            return ['said' => "Done: {$record['said']} They can undo it on the card.", 'block' => $record['block']];
        } catch (ActionRefused $refused) {
            return ['said' => 'Not done: '.$refused->getMessage(), 'block' => null];
        }
    }

    /**
     * Apply on a proposal: made as it was proposed. A proposal past its time, or whose
     * record changed meanwhile, is refused and stays as it is.
     *
     * @return array{block: array<string, mixed>, workspace: ?array<string, mixed>, refusal: ?string}
     */
    public function apply(User $user, string $token, CarbonImmutable $now): array
    {
        $record = $this->workspace->action($user, $token) ?? abort(404);
        if ($record['state'] !== 'proposed') {
            return ['block' => $record['block'], 'workspace' => null, 'refusal' => null];
        }
        if (CarbonImmutable::parse($record['expires_at'])->isPast()) {
            return ['block' => $record['block'], 'workspace' => null, 'refusal' => 'This suggestion expired. Please ask again.'];
        }
        try {
            $record = $this->made($user, $record, $now);
        } catch (ActionRefused $refused) {
            return ['block' => $record['block'], 'workspace' => null, 'refusal' => $refused->getMessage()];
        }

        return ['block' => $record['block'], 'workspace' => $record['workspace'], 'refusal' => null];
    }

    /**
     * Undo on a change made in the last 24 hours, through the app's own way back.
     *
     * @return array{block: array<string, mixed>, workspace: ?array<string, mixed>, refusal: ?string}
     */
    public function undo(User $user, string $token, CarbonImmutable $now): array
    {
        $record = $this->workspace->action($user, $token) ?? abort(404);
        if ($record['state'] !== 'done') {
            return ['block' => $record['block'], 'workspace' => null, 'refusal' => null];
        }
        if (CarbonImmutable::parse($record['expires_at'])->isPast()) {
            return ['block' => $record['block'], 'workspace' => null, 'refusal' => 'This change can no longer be undone here. Change it back where it’s kept.'];
        }
        try {
            $undone = app(self::ACTIONS[$record['action']][0])->undo($user, $record['action'], $record['undo'], $now);
        } catch (ActionRefused $refused) {
            return ['block' => $record['block'], 'workspace' => null, 'refusal' => $refused->getMessage()];
        }
        $record = $this->kept($user, [...$record, 'state' => 'undone'], $undone);

        return ['block' => $record['block'], 'workspace' => $record['workspace'], 'refusal' => null];
    }

    /** Whether they asked to be asked first for this kind of change (Settings › Composer). */
    public function asksFirst(User $user, string $domain): bool
    {
        return (bool) $user->setting("composer_ask_first_{$domain}");
    }

    /**
     * Makes a prepared change and keeps what undoes it.
     *
     * @return array<string, mixed> the record, with its card
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
     * Keeps the record with its card as it is now, wherever an answer shows it.
     *
     * @param  array<string, mixed>  $fields  the card's line (`title`) and what else it shows
     * @return array<string, mixed>
     */
    private function kept(User $user, array $record, array $fields): array
    {
        $record['block'] = array_filter([
            'type' => 'changed', 'id' => $record['token'], 'action' => $record['action'], 'domain' => $record['domain'], 'state' => $record['state'],
            'title' => $fields['title'],
            // Undone, a card is its line alone.
            ...($record['state'] === 'undone' ? [] : array_intersect_key($fields, array_flip(self::SHOWN))),
            'apply' => $record['state'] === 'proposed' ?: null,
            'undo' => $record['state'] === 'done' ?: null,
            'expires_at' => $record['state'] === 'undone' ? null : $record['expires_at'],
            // Composer's own words, said when the AI said nothing.
            'text' => $fields['title'].(str_ends_with($fields['title'], '?') || str_ends_with($fields['title'], '.') ? '' : '.')
                .($record['state'] === 'proposed' ? ' Tap Apply to make the change.' : ''),
        ], fn ($v) => $v !== null);
        $record['workspace'] = $this->workspace->keepAction($user, array_diff_key($record, ['workspace' => true]));

        return $record;
    }
}
