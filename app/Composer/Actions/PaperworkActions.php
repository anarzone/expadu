<?php

namespace App\Composer\Actions;

use App\Bureaucracy\Facts\CalendarDate;
use App\Bureaucracy\Facts\ConfirmedFactView;
use App\Bureaucracy\Facts\CorrectFact;
use App\Bureaucracy\Facts\FactDefinition;
use App\Bureaucracy\Facts\FactInputMethod;
use App\Bureaucracy\Facts\FactRegistry;
use App\Bureaucracy\Facts\RecordFactChange;
use App\Bureaucracy\Processes\RecordProcessEvent;
use App\Bureaucracy\Processes\StartProcess;
use App\Bureaucracy\ReadModel\AccountPlanEntry;
use App\Bureaucracy\ReadModel\PaperworkPlanDiff;
use App\Models\BureaucracyPerson;
use App\Models\BureaucracyProcess;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Changes to the person's own Paperwork record, through Paperwork's own validated writes
 * (RecordFactChange, StartProcess, RecordProcessEvent) and with its own Undo (CorrectFact,
 * the opposite report). What changed in their plan is Paperwork's before and after
 * (PaperworkPlanDiff), in its cards' own words and dates: nothing here is worked out or
 * written by the AI, and nothing is sent to an authority.
 */
final class PaperworkActions implements ComposerAction
{
    /** Each answer as the record says it: its row's label, what a proposal asks to change, the done line and the undone line. */
    private const LABELS = [
        'current_residence_title' => ['Residence title', 'your residence title', 'Your residence title is now %s', 'Your residence title is %s again'],
        'visa_expires_at' => ['Your visa runs until', 'when your visa runs out', 'Your visa now runs until %s', 'Your visa runs until %s again'],
        'residence_title_expires_at' => ['Your residence permit runs until', 'when your residence permit runs out', 'Your residence permit now runs until %s', 'Your residence permit runs until %s again'],
        'residence_card_expires_at' => ['Your residence card runs until', 'when your residence card runs out', 'Your residence card now runs until %s', 'Your residence card runs until %s again'],
        'arrival_date' => ['You arrived on', 'when you arrived', 'You arrived on %s', 'You arrived on %s again'],
        'moved_in_at' => ['You moved in on', 'when you moved in', 'You moved in on %s', 'Your move-in day is %s again'],
        'registration_status' => ['Registered at your address', 'whether you’re registered at your address', 'Registered at your address: %s', 'Registered at your address: %s again'],
        'health_coverage_confirmed' => ['Health insurance', 'your health insurance', 'Health insurance: %s', 'Health insurance: %s again'],
        'tax_id_available' => ['Tax ID', 'your tax ID', 'Tax ID: %s', 'Tax ID: %s again'],
        'german_level' => ['German level', 'your German level', 'Your German level is now %s', 'Your German level is %s again'],
    ];

    /** The record's rows, in its order, as the Paperwork board on the canvas shows them. */
    private const ROWS = ['current_residence_title', 'visa_expires_at', 'residence_title_expires_at', 'residence_card_expires_at', 'arrival_date', 'moved_in_at',
        'registration_status', 'health_coverage_confirmed', 'tax_id_available', 'german_level'];

    /**
     * Values said in words, as Paperwork's Your situation says them (facts.ts), so a card, a
     * record row and Paperwork read the same: a title's name, and what a yes or no means for
     * that answer. An option whose words depend on the answer it's for is under that answer.
     */
    private const WORDS = [
        'national_d_visa' => 'National D visa', 'standard_work_permit' => 'Work permit', 'blue_card' => 'EU Blue Card', 'blue_card_pending' => 'Blue Card applied for',
        'family_reunification' => 'Family reunification', 'settlement_permit_9' => 'Settlement permit · §9', 'settlement_permit_18c' => 'Settlement permit · §18c',
        'settlement_permit_unknown' => 'Settlement permit · type unknown', 'registered' => 'Yes, registered', 'not_registered' => 'Not yet',
        'current_residence_title' => ['other' => 'Another title', 'none' => 'No residence title yet'],
    ];

    /** An option in a line, where its name alone doesn't say what it is (a settlement permit's section). */
    private const HINTS = [
        'settlement_permit_9' => 'Niederlassungserlaubnis under §9 of the Residence Act: the general settlement permit.',
        'settlement_permit_18c' => 'Niederlassungserlaubnis under §18c: the one for skilled workers and EU Blue Card holders.',
    ];

    /** What their own words must say for one of a group's options to be proposed: its section. */
    private const SECTIONS = [
        'settlement_permit_9' => '/(?:§|section|paragraph|para\.?|absatz)\s*9(?!\s*a)(?!\d)/iu',
        'settlement_permit_18c' => '/18\s*c(?![a-z])/iu',
    ];

    /**
     * An option meaning "one of these, not known which", and the others it stands for (those
     * sharing its name): asked about instead of kept (owner, 27 Oct 2026: choices, not "unknown").
     * The card's question, what "Not sure" says, and how it keeps this one.
     */
    private const UNSURE = [
        'settlement_permit_unknown' => ['Which settlement permit do you have?', [
            'Your residence card, or the letter that came with it, may name the section of the Residence Act it was given under.',
            'If it names neither of these, or you can’t tell, keep “type unknown”: Paperwork works with it, and you can change it here any time.',
        ], 'Keep “type unknown”'],
    ];

    private const BOOLEANS = ['health_coverage_confirmed' => ['Confirmed with my insurer', 'Not yet'], 'tax_id_available' => ['I have it', 'Not yet']];

    private const NOTE = 'Saved only for you; nothing is sent to an authority.';

    /** An answer without a value, as Paperwork's Your situation says it (facts.ts). */
    private const STATES = ['unknown' => 'I’m not sure', 'declined' => 'Prefer not to say', 'not_applicable' => 'Doesn’t apply to me'];

    public function __construct(private FactRegistry $registry, private ConfirmedFactView $facts, private AccountPlanEntry $plans) {}

    /**
     * Their record as a card, and as the AI reads it (only with their Paperwork permission for
     * this message): the answers it keeps, and their open tasks with each step's id.
     *
     * @return array{text: string, block: ?array<string, mixed>}
     */
    public function record(User $user, CarbonImmutable $now): array
    {
        $person = $this->person($user);
        $view = $this->view($person);
        $plan = $this->plan($user);
        $rows = [];
        foreach (self::ROWS as $key) {
            if (($view['states'][$key] ?? null) !== null || isset($view['values'][$key])) {
                // Every answer changes in place on the card (owner, 27 Oct 2026): a date field, or the
                // answer's own choices, as Paperwork's Your situation offers them.
                $definition = $this->registry->definition($key);
                $current = ($view['states'][$key] ?? null) === 'value' ? $view['values'][$key] : null;
                $rows[] = array_filter(['key' => $key, 'label' => self::LABELS[$key][0], 'value' => $this->said($key, $view, $now),
                    'edit' => match ($definition->type) {
                        'date' => ['kind' => 'date', 'value' => $current],
                        'enum', 'boolean' => ['kind' => 'choice', 'type' => $definition->type, 'value' => $current, 'options' => $definition->options],
                        default => null,
                    }], fn ($v) => $v !== null);
            }
        }
        $tasks = collect($plan['processes'] ?? [])->filter(fn (array $p): bool => ! ($p['is_closed'] ?? false))->map(fn (array $p): string => "Task \"{$p['title']}\" (task {$p['occurrence_key']}): "
            .collect($p['steps'] ?? [])->map(fn (array $s): string => "step {$s['step_id']} \"{$s['title']}\", ".($s['status'] === 'completed' ? 'done' : 'not done')
                .collect($s['dates'] ?? [])->filter(fn (array $d): bool => is_string($d['date'] ?? null))->map(fn (array $d): string => ', aim for '.self::day($d['date']))->first())->implode('; '))
            ->implode("\n");
        $answers = collect($rows)->map(fn (array $r): string => "{$r['label']}: {$r['value']} (key {$r['key']})")->implode('; ');
        // The answers without a row on the card (what they want next, a sponsor's title…): read back to the AI, not shown.
        $others = $this->registry->all()->keys()->diff(self::ROWS)
            ->filter(fn (string $key): bool => ($view['states'][$key] ?? null) !== null || isset($view['values'][$key]))
            ->map(fn (string $key): string => "{$key} (".$this->registry->definition($key)->question.'): '.$this->said($key, $view, $now))->implode('; ');

        return ['text' => 'Their Paperwork record, shown to them as a card. Answers: '.($answers ?: 'none yet').".\n".($others !== '' ? "Other answers: {$others}.\n" : '').($tasks ?: 'No open tasks.'),
            'block' => ['type' => 'record', 'domain' => 'paperwork', 'title' => 'Your Paperwork record', 'rows' => $rows,
                'link' => ['label' => 'Open Paperwork →', 'href' => route('paperwork', [], false)]]];
    }

    public function prepare(User $user, string $action, array $input, CarbonImmutable $now): array
    {
        $person = $this->person($user);

        return match ($action) {
            'change_paperwork_fact' => $this->prepareFact($user, $person, $input, $now),
            'paperwork_step' => $this->prepareStep($user, $input),
            'paperwork_report' => ($input['event'] ?? null) === 'reopened' ? $this->prepareReopen($user, $input) : $this->prepareReport($user, $input, $now),
            default => throw new ActionRefused('Composer can’t change that in Paperwork.'),
        };
    }

    public function run(User $user, string $action, array $prepared, CarbonImmutable $now): array
    {
        $person = $this->person($user);
        $before = $this->plan($user);
        try {
            $made = match ($action) {
                'change_paperwork_fact' => (function () use ($user, $person, $prepared): array {
                    // Typed into its row by them: their own answer. Read from their words by the AI and
                    // applied by their tap: a confirmed extraction.
                    $fact = app(RecordFactChange::class)->execute($user, $person, $prepared['key'], $prepared['value'], null, $prepared['revision'], $prepared['set'] ?? 'value',
                        ($prepared['manual'] ?? false) ? FactInputMethod::Structured : FactInputMethod::ConfirmedExtraction);

                    // "Your visa now runs until 12 Feb 2027"; an answer without a value as its row says it.
                    return ['title' => ($prepared['set'] ?? 'value') === 'value' ? sprintf(self::LABELS[$prepared['key']][2] ?? (self::label($prepared['key']).': %s'), $prepared['to'])
                        : self::label($prepared['key']).': '.$prepared['to'],
                        'sub' => ($prepared['from'] !== null ? "It was {$prepared['from']}. " : '').self::NOTE,
                        'undo' => ['kind' => 'fact', 'fact' => $fact->id, 'revision' => (int) $fact->case->fact_version, 'key' => $prepared['key'],
                            'previous' => $prepared['previous'], 'state' => $prepared['state'], 'from' => $prepared['from']]];
                })(),
                'paperwork_step' => (function () use ($user, $person, $prepared): array {
                    [$process, $version, $token] = $this->tracked($user, $person, $prepared);
                    app(RecordProcessEvent::class)->execute($user, $process, $prepared['done'] ? 'step_completed' : 'step_reopened', ['step_id' => $prepared['step']],
                        $version, (string) Str::uuid(), $token);

                    return ['title' => "Marked “{$prepared['name']}” as ".($prepared['done'] ? 'done' : 'not done'), 'sub' => "{$prepared['task']}. ".self::NOTE,
                        'undo' => ['kind' => 'step', 'process' => $process->id, 'step' => $prepared['step'], 'done' => $prepared['done'], 'name' => $prepared['name']]];
                })(),
                default => (function () use ($user, $person, $prepared): array {
                    [$process, $version, $token] = $this->tracked($user, $person, $prepared);
                    if ($prepared['start_first'] ?? false) {
                        app(RecordProcessEvent::class)->execute($user, $process, 'preparation_started', [], $version, (string) Str::uuid(), $token);
                        [$process, $version, $fresh] = $this->current($user, $process->id);
                        $token = $fresh ?? $token;
                    }
                    $event = app(RecordProcessEvent::class)->execute($user, $process, $prepared['event'], $prepared['payload'], $version, (string) Str::uuid(), $token);

                    return ['title' => $prepared['done'], 'sub' => "{$prepared['task']}. ".self::NOTE,
                        'undo' => ['kind' => $prepared['event'], 'process' => $process->id, 'event' => $event->id, 'appointment' => $prepared['payload']['appointment_id'] ?? null,
                            'task' => $prepared['task'], 'was' => $prepared['was'] ?? null]];
                })(),
            };
        } catch (Throwable $error) {
            throw self::refusal($error);
        }
        $changes = PaperworkPlanDiff::between($before, $this->plan($user));

        // What changed in their plan, as Paperwork's before and after say it (the page words its dates).
        return [...$made, 'changes' => $changes, 'link' => ['label' => 'Open Paperwork →', 'href' => route('paperwork', [], false)],
            'said' => $made['title'].'.'.(($moved = self::moved($changes)) !== null ? " {$moved}" : '')];
    }

    public function undo(User $user, string $action, array $undo, CarbonImmutable $now): array
    {
        $person = $this->person($user);
        $before = $this->plan($user);
        try {
            $title = match ($undo['kind']) {
                'fact' => (function () use ($user, $person, $undo): string {
                    $state = in_array($undo['state'], ['value', 'unknown', 'declined', 'not_applicable'], true) ? $undo['state'] : 'unknown';
                    app(CorrectFact::class)->execute($user, $person, $undo['fact'], $state === 'value' ? $undo['previous'] : null, $undo['revision'], $state);

                    return $undo['from'] !== null && $state === 'value'
                        ? 'Undone. '.sprintf(self::LABELS[$undo['key']][3] ?? (self::label($undo['key']).': %s again'), $undo['from'])
                        : 'Undone. '.self::label($undo['key']).' is back to not known';
                })(),
                'step' => (function () use ($user, $undo): string {
                    [$process, $version, $token] = $this->current($user, $undo['process']);
                    app(RecordProcessEvent::class)->execute($user, $process, $undo['done'] ? 'step_reopened' : 'step_completed', ['step_id' => $undo['step']], $version, (string) Str::uuid(), $token);

                    return "Undone. “{$undo['name']}” is ".($undo['done'] ? 'open again' : 'marked done again');
                })(),
                // Closed: open again (Paperwork undoes work by reopening it, never by rewriting what was reported).
                'completion_reported', 'cancellation_reported' => (function () use ($user, $undo): string {
                    [$process, $version] = $this->current($user, $undo['process']);
                    app(RecordProcessEvent::class)->execute($user, $process, 'process_reopened', [], $version, (string) Str::uuid());

                    return "Undone. {$undo['task']} is open again";
                })(),
                'process_reopened' => (function () use ($user, $undo): string {
                    [$process, $version] = $this->current($user, $undo['process']);
                    $closed = ($undo['was'] ?? 'completed') === 'cancelled';
                    app(RecordProcessEvent::class)->execute($user, $process, $closed ? 'cancellation_reported' : 'completion_reported', [], $version, (string) Str::uuid());

                    return "Undone. {$undo['task']} is marked ".($closed ? 'cancelled' : 'completed').' again';
                })(),
                'submission_recorded' => (function () use ($user, $undo): string {
                    [$process, $version] = $this->current($user, $undo['process']);
                    app(RecordProcessEvent::class)->execute($user, $process, 'submission_retracted', ['event_id' => $undo['event']], $version, (string) Str::uuid());

                    return "Undone. The report that you submitted {$undo['task']} is withdrawn";
                })(),
                default => (function () use ($user, $undo): string {
                    [$process, $version] = $this->current($user, $undo['process']);
                    app(RecordProcessEvent::class)->execute($user, $process, 'appointment_cancelled', ['appointment_id' => $undo['appointment']], $version, (string) Str::uuid());

                    return "Undone. That {$undo['task']} appointment is off your record";
                })(),
            };
        } catch (Throwable $error) {
            throw self::refusal($error);
        }
        // "…, and both steps are back to 1 Oct."
        $moved = collect(PaperworkPlanDiff::between($before, $this->plan($user)))->where('change', 'due_moved');
        $to = $moved->pluck('to')->unique();

        return ['title' => $title.($moved->isNotEmpty() && $to->count() === 1
            ? ', and '.($moved->count() === 1 ? 'its step is' : ($moved->count() === 2 ? 'both steps are' : "{$moved->count()} steps are")).' back to '.self::day($to->first(), false).'.' : '.')];
    }

    /** @return array<string, mixed> */
    private function prepareFact(User $user, BureaucracyPerson $person, array $input, CarbonImmutable $now): array
    {
        $key = (string) ($input['key'] ?? '');
        // Not sure, rather not say, or doesn't apply, as Paperwork's Your situation offers them: no value.
        $set = in_array($input['state'] ?? null, array_keys(self::STATES), true) ? $input['state'] : 'value';
        try {
            $definition = $this->registry->definition($key);
            $value = $set === 'value' ? $definition->normalize(self::typed($definition, $input['value'] ?? null)) : null;
        } catch (DomainException) {
            throw new ActionRefused($this->registry->all()->has($key) ? 'That isn’t an answer Paperwork takes for it.' : 'Paperwork doesn’t keep that answer.');
        }
        // Only the questions Paperwork offers "Doesn’t apply to me" on take it.
        if ($set === 'not_applicable' && ! $definition->allowsNotApplicable) {
            throw new ActionRefused('Paperwork doesn’t offer “Doesn’t apply to me” for this. It can be “Not sure”, “Rather not say”, or an answer.');
        }
        $view = $this->view($person);
        $state = $view['states'][$key] ?? 'unknown';
        if ($state === 'conflict') {
            throw new ActionRefused('Paperwork has two different answers for this, settled in Paperwork: open_in_app "paperwork_conflict".');
        }
        if ($set === 'value' ? $state === 'value' && ($view['values'][$key] ?? null) === $value : $state === $set) {
            throw new ActionRefused('Paperwork already says so.');
        }
        $from = $state === 'value' ? self::value($key, $view['values'][$key] ?? null) : (self::STATES[$state] ?? null);
        $to = $set === 'value' ? self::value($key, $value) : self::STATES[$set];

        return ['key' => $key, 'value' => $value, 'set' => $set, 'previous' => $view['values'][$key] ?? null, 'state' => $state, 'revision' => (int) $view['revision'],
            'from' => $from, 'to' => $to, 'propose' => 'Change '.(self::LABELS[$key][1] ?? 'this answer').'?', 'sub' => $this->dated($user, $key),
            'date' => $definition->type === 'date' && $set === 'value',
            // Both ways of saying a date, so the AI may say either.
            'about' => self::label($key).': '.($from !== null ? 'from '.self::long($view['values'][$key] ?? null, $from).' ' : '').'to '.self::long($value, $to)];
    }

    /**
     * Their words fit more than one of an answer's options (a settlement permit, without its
     * section): the options to ask them about, each with a line on what it is, instead of
     * proposing one, or proposing "not known". Asked for by the AI (`choices`), when it would
     * propose a "not known" option that stands for others, or one of them their words (`_said`)
     * don't name: a Niederlassungserlaubnis isn't §9 until they say so. Null when there's one
     * answer to propose.
     *
     * @param  array<string, mixed>  $input  the tool's input
     * @return array{key: string, title: string, sub: string, value: ?string, options: list<array{value: string, label: string, hint: ?string}>, unsure: ?array<string, mixed>, allowed: list<string>}|null
     *
     * @throws ActionRefused
     */
    public function choices(User $user, array $input): ?array
    {
        $key = (string) ($input['key'] ?? '');
        if (! $this->registry->all()->has($key) || ($definition = $this->registry->definition($key))->type !== 'enum') {
            return null;
        }
        $value = $input['value'] ?? null;
        $stands = fn (string $group): array => array_values(array_filter($definition->options,
            fn (string $o): bool => $o !== $group && str_starts_with($o, substr($group, 0, -strlen('unknown')))));
        // One of a group's options their own words don't name stands for the group: asked about.
        $guessed = is_string($value) && isset(self::SECTIONS[$value]) && is_string($input['_said'] ?? null) && $input['_said'] !== '' && preg_match(self::SECTIONS[$value], $input['_said']) !== 1;
        if ($guessed) {
            $value = collect(array_keys(self::UNSURE))->first(fn (string $group): bool => in_array($value, $stands($group), true)) ?? $value;
        }
        $unsure = is_string($value) && isset(self::UNSURE[$value]) && in_array($value, $definition->options, true) ? $value : null;
        $picked = $unsure !== null ? [$unsure] : array_map(fn (mixed $c): string => (string) $c, (array) ($input['choices'] ?? []));
        // A "not known" one among them is what they're unsure between: its options, and "Not sure" keeps it.
        foreach (array_keys(self::UNSURE) as $group) {
            if (in_array($group, $picked, true) && in_array($group, $definition->options, true)) {
                $picked = [...array_diff($picked, [$group]), ...$stands($group)];
                $unsure = $group;
            }
        }
        $asked = array_values(array_intersect($definition->options, $picked));
        if (count($asked) < 2) {
            return null;
        }
        // The "not known" one the options all stand under, if any: "Not sure" keeps it.
        $unsure ??= collect(array_keys(self::UNSURE))->first(fn (string $group): bool => in_array($group, $definition->options, true)
            && array_diff($asked, $stands($group)) === []);
        $view = $this->view($this->person($user));
        $current = ($view['states'][$key] ?? null) === 'value' ? $view['values'][$key] ?? null : null;
        [$title, $lines, $keep] = $unsure !== null ? self::UNSURE[$unsure] : ['Which one is yours?', [], null];
        // Its own question only when every option is one of its kind.
        $title = $unsure !== null && array_diff($asked, $stands($unsure)) === [] ? $title : 'Which one is yours?';

        return ['key' => $key, 'title' => $title, 'sub' => (string) $definition->question,
            'value' => in_array($current, $asked, true) ? $current : null,
            'options' => array_map(fn (string $o): array => ['value' => $o, 'label' => (string) self::value($key, $o), 'hint' => self::HINTS[$o] ?? null], $asked),
            'unsure' => $unsure !== null ? ['label' => 'Not sure', 'lines' => $lines, 'keep' => ['value' => $unsure, 'label' => $keep]] : null,
            'allowed' => [...$asked, ...($unsure !== null ? [$unsure] : [])]];
    }

    /** @return array<string, mixed> */
    private function prepareStep(User $user, array $input): array
    {
        $process = $this->task($user, (string) ($input['task'] ?? ''));
        $said = mb_strtolower(trim((string) ($input['step'] ?? '')));
        $steps = array_values(array_filter($process['steps'] ?? [], fn (array $s): bool => $s['step_id'] === ($input['step'] ?? null)
            || ($said !== '' && str_contains(mb_strtolower((string) $s['title']), $said))));
        if (count($steps) !== 1) {
            throw new ActionRefused($steps === [] ? "That step isn’t in {$process['title']}." : "More than one step in {$process['title']} fits. Ask which.");
        }
        $done = ($input['done'] ?? true) !== false;
        if (($steps[0]['status'] === 'completed') === $done) {
            throw new ActionRefused("“{$steps[0]['title']}” is ".($done ? 'already done.' : 'not marked done.'));
        }

        return [...$this->process($process), 'step' => $steps[0]['step_id'], 'name' => (string) $steps[0]['title'], 'done' => $done,
            'propose' => "Mark “{$steps[0]['title']}” as ".($done ? 'done' : 'not done').'?', 'sub' => (string) $process['title']];
    }

    /** @return array<string, mixed> */
    private function prepareReport(User $user, array $input, CarbonImmutable $now): array
    {
        // Where it stands with the office, or not tracking it: reported on the task in Paperwork, where
        // each report can be corrected (open_in_app paperwork_task_status), whichever task it is.
        if (! in_array($input['event'] ?? null, ['submitted', 'appointment', 'completed', 'cancelled'], true)) {
            throw new ActionRefused('Composer records that they submitted something, an appointment, or that a task is completed, cancelled or reopened. '
                .'Waiting for the office, the office needing something, being held up or not tracking a task are reported in Paperwork: open_in_app "paperwork_task_status".');
        }
        $process = $this->task($user, (string) ($input['task'] ?? ''));
        $date = is_string($input['date'] ?? null) ? $input['date'] : null;
        if (($input['event'] ?? null) === 'submitted') {
            if (CalendarDate::historical($date) === null) {
                throw new ActionRefused('Say the day they submitted it, on or before today.');
            }
            $channel = in_array($input['channel'] ?? null, ['online', 'post', 'email', 'in_person'], true) ? $input['channel'] : null;

            return [...$this->process($process), 'event' => 'submission_recorded', 'payload' => array_filter(['occurred_on' => $date, 'channel' => $channel]),
                'propose' => "Record that you submitted {$process['title']} on ".self::day($date).'?', 'sub' => self::NOTE,
                'done' => "Recorded: you submitted {$process['title']} on ".self::day($date)];
        }
        if (($input['event'] ?? null) === 'appointment') {
            $time = is_string($input['time'] ?? null) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $input['time']) === 1 ? $input['time'] : null;
            if (CalendarDate::parse($date) === null || $time === null) {
                throw new ActionRefused('Say the appointment’s day and time.');
            }
            $at = CarbonImmutable::parse("{$date} {$time}", 'Europe/Berlin');
            $minutes = is_int($input['minutes'] ?? null) && $input['minutes'] > 0 && $input['minutes'] <= 1440 ? $input['minutes'] : null;

            return [...$this->process($process), 'event' => 'appointment_recorded',
                'payload' => ['appointment_id' => (string) Str::uuid(), 'starts_at' => $at->format('Y-m-d\TH:i:sP'), 'timezone' => 'Europe/Berlin', 'duration_minutes' => $minutes],
                'propose' => "Record your {$process['title']} appointment on ".self::day($date)." at {$time}?", 'sub' => self::NOTE,
                'done' => "Recorded your {$process['title']} appointment, ".self::day($date)." at {$time}"];
        }
        if (in_array($input['event'] ?? null, ['completed', 'cancelled'], true)) {
            $done = $input['event'] === 'completed';
            if ($date !== null && CalendarDate::historical($date) === null) {
                throw new ActionRefused('Say the day it happened, on or before today, or leave it out.');
            }

            return [...$this->process($process), 'event' => $done ? 'completion_reported' : 'cancellation_reported', 'payload' => array_filter(['occurred_on' => $date]),
                // Never started in Paperwork: it's started first, as reporting it there does.
                'start_first' => in_array($process['state']['workflow'] ?? 'not_started', ['not_started', 'blocked'], true),
                'propose' => "Mark {$process['title']} as ".($done ? 'completed' : 'cancelled').'?', 'sub' => self::NOTE,
                'done' => "{$process['title']} is marked ".($done ? 'completed' : 'cancelled')];
        }

        throw new LogicException('Every report Composer records is handled above.');
    }

    /**
     * A task they completed or cancelled, opened again (Paperwork's "Reopen this task"); Undo
     * closes it again as it was.
     *
     * @return array<string, mixed>
     */
    private function prepareReopen(User $user, array $input): array
    {
        $said = mb_strtolower(trim((string) ($input['task'] ?? '')));
        $found = array_values(array_filter($this->plan($user)['processes'] ?? [], fn (array $p): bool => ($p['is_closed'] ?? false)
            && ($p['occurrence_key'] === ($input['task'] ?? null) || ($said !== '' && str_contains(mb_strtolower((string) $p['title']), $said)))));
        if (count($found) !== 1) {
            throw new ActionRefused($found === [] ? 'No completed or cancelled Paperwork task fits that.' : 'More than one closed Paperwork task fits. Ask which.');
        }
        $was = ($found[0]['state']['workflow'] ?? 'completed') === 'cancelled' ? 'cancelled' : 'completed';

        return [...$this->process($found[0]), 'event' => 'process_reopened', 'payload' => [], 'was' => $was,
            'propose' => "Reopen {$found[0]['title']}?", 'sub' => self::NOTE, 'done' => "{$found[0]['title']} is open again"];
    }

    /**
     * Their own record, in use: not erased, and its processing not stopped.
     *
     * @throws ActionRefused
     */
    private function person(User $user): BureaucracyPerson
    {
        $person = BureaucracyPerson::query()->where('account_user_id', $user->id)->first();
        if ($person === null || $person->record_status !== 'active' || $person->processing_stopped_at !== null || $person->dossier()->doesntExist()) {
            throw new ActionRefused('There’s no Paperwork record in use to change. Paperwork sets one up from your situation: open_in_app "paperwork_get_started".');
        }

        return $person;
    }

    /** @return array<string, mixed> its answers now (ConfirmedFactView) */
    private function view(BureaucracyPerson $person): array
    {
        return $this->facts->forCase($person->dossier()->firstOrFail(), now('Europe/Berlin')->toDateString());
    }

    /**
     * Their plan as Paperwork reads it now (live from their answers, never cached).
     *
     * @return array<string, mixed>
     *
     * @throws ActionRefused
     */
    private function plan(User $user): array
    {
        try {
            $plan = $this->plans->for($user)['plan'] ?? null;
        } catch (HttpException) {
            throw new ActionRefused('Paperwork needs your email confirmed first.');
        }
        if (! is_array($plan)) {
            throw new ActionRefused('Your Paperwork plan isn’t available right now.');
        }

        return $plan;
    }

    /**
     * The open task they mean, by its key or its title.
     *
     * @return array<string, mixed>
     *
     * @throws ActionRefused
     */
    private function task(User $user, string $task): array
    {
        $said = mb_strtolower(trim($task));
        $found = array_values(array_filter($this->plan($user)['processes'] ?? [], fn (array $p): bool => ! ($p['is_closed'] ?? false)
            && ($p['occurrence_key'] === $task || ($said !== '' && str_contains(mb_strtolower((string) $p['title']), $said)))));
        if (count($found) !== 1) {
            throw new ActionRefused($found === [] ? 'That task isn’t in your Paperwork plan.' : 'More than one Paperwork task fits. Ask which.');
        }

        return $found[0];
    }

    /** @return array<string, mixed> what a report on this task needs: the task, as it was shown */
    private function process(array $process): array
    {
        return ['process' => $process['id'], 'occurrence' => $process['occurrence_key'], 'review_token' => $process['review_token'],
            'version' => (int) $process['version'], 'jurisdiction' => $process['jurisdiction'], 'task' => (string) $process['title']];
    }

    /**
     * The task as a tracked process, started when it isn't yet (opening it records no progress),
     * with the review token to write with: starting it changes the token, so a just-started process
     * takes its fresh one, never the plan's from before.
     *
     * @return array{0: BureaucracyProcess, 1: int, 2: ?string}
     */
    private function tracked(User $user, BureaucracyPerson $person, array $prepared): array
    {
        $id = $prepared['process'] ?? null;
        if ($id !== null) {
            $process = BureaucracyProcess::query()->findOrFail($id);

            return [$process, (int) $process->version, $prepared['review_token']];
        }
        $id = app(StartProcess::class)->execute($user, $person, $prepared['jurisdiction'], $prepared['occurrence'], $prepared['review_token'], (string) Str::uuid())['process_id'];
        [$process, $version, $token] = $this->current($user, $id);

        return [$process, $version, $token ?? $prepared['review_token']];
    }

    /**
     * A tracked process as it is now, for Undo: its version and the review token of the steps it shows.
     *
     * @return array{0: BureaucracyProcess, 1: int, 2: ?string}
     */
    private function current(User $user, int $id): array
    {
        $process = BureaucracyProcess::query()->findOrFail($id);
        $shown = collect($this->plan($user)['processes'] ?? [])->firstWhere('id', $id);

        return [$process, (int) $process->version, $shown['review_token'] ?? null];
    }

    /** "Two EU Blue Card steps are dated from it, so they move with it.": the steps whose dates this answer sets. */
    private function dated(User $user, string $key): ?string
    {
        $dated = collect($this->plan($user)['processes'] ?? [])->filter(fn (array $p): bool => ! ($p['is_closed'] ?? false))
            ->mapWithKeys(fn (array $p): array => [(string) $p['title'] => collect($p['steps'] ?? [])->filter(fn (array $s): bool => collect($s['dates'] ?? [])
                ->contains(fn (array $d): bool => ($d['anchor_fact'] ?? null) === $key || ($d['needed_fact'] ?? null) === $key))->count()])
            ->filter();
        if ($dated->isEmpty()) {
            return null;
        }
        $words = $dated->map(fn (int $n, string $title): string => ($n === 1 ? 'One' : ucfirst(self::NUMBERS[$n] ?? (string) $n))." {$title} step".($n === 1 ? ' is' : 's are'))->values();

        return $words->implode(', and ').' dated from it, so '.($dated->sum() === 1 ? 'it moves' : 'they move').' with it.';
    }

    private const NUMBERS = [2 => 'two', 3 => 'three', 4 => 'four', 5 => 'five', 6 => 'six'];

    /** "EU Blue Card: 2 steps now aim for 12 Feb 2027." for the AI to say, from the plan's own dates. */
    private static function moved(array $changes): ?string
    {
        $moved = collect($changes)->where('change', 'due_moved');
        if ($moved->isEmpty()) {
            return null;
        }

        return $moved->groupBy('title')->map(fn ($rows, string $title): string => "{$title}: ".$rows->count().' '.($rows->count() === 1 ? 'step' : 'steps')
            .' now '.($rows->count() === 1 ? 'aims' : 'aim').' for '.implode(' and ', $rows->pluck('to')->unique()->map(fn (string $d): string => self::day($d))->all()).'.')->implode(' ');
    }

    /** A value from the AI as its type wants it: a date as YYYY-MM-DD, yes/no, a whole number, an option. */
    private static function typed(FactDefinition $definition, mixed $value): mixed
    {
        return match ($definition->type) {
            'boolean' => is_bool($value) ? $value : (is_string($value) && in_array(mb_strtolower($value), ['yes', 'true'], true) ? true
                : (is_string($value) && in_array(mb_strtolower($value), ['no', 'false'], true) ? false : $value)),
            'integer' => is_string($value) && ctype_digit($value) ? (int) $value : $value,
            default => is_string($value) ? trim($value) : $value,
        };
    }

    /** A value as the record says it. */
    private static function value(string $key, mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => (self::BOOLEANS[$key] ?? ['Yes', 'No'])[$value ? 0 : 1],
            is_string($value) && CalendarDate::parse($value) !== null => self::day($value),
            is_string($value) => self::WORDS[$key][$value] ?? (is_string(self::WORDS[$value] ?? null) ? self::WORDS[$value] : ucfirst(str_replace('_', ' ', $value))),
            default => (string) $value,
        };
    }

    /** A row's value: the answer, or that it isn't known yet. */
    private function said(string $key, array $view, CarbonImmutable $now): string
    {
        return match ($view['states'][$key] ?? null) {
            'value' => self::value($key, $view['values'][$key] ?? null) ?? 'Not known',
            'conflict' => 'Two answers to settle',
            'not_applicable' => 'Doesn’t apply',
            default => in_array($key, ['registration_status', 'health_coverage_confirmed', 'tax_id_available'], true) ? 'Not yet' : 'Not known',
        };
    }

    /** A date as the record says it and in full ("12 Feb 2027, 12 February 2027"); else the value as said. */
    private static function long(mixed $value, string $said): string
    {
        return is_string($value) && CalendarDate::parse($value) !== null ? "{$said} (".CarbonImmutable::parse($value, 'Europe/Berlin')->format('j F Y').')' : $said;
    }

    /** What an answer is, as the record's row says it ("Your visa runs until"), or null when it has no row. */
    public static function rowLabel(string $key): ?string
    {
        return self::LABELS[$key][0] ?? null;
    }

    private static function label(string $key): string
    {
        return self::LABELS[$key][0] ?? ucfirst(str_replace('_', ' ', $key));
    }

    /** "12 Feb 2027", or "12 Feb" in a step's row. */
    private static function day(string $date, bool $year = true): string
    {
        return CarbonImmutable::parse($date, 'Europe/Berlin')->format($year ? 'j M Y' : 'j M');
    }

    /** Paperwork's own refusal, in its words. */
    private static function refusal(Throwable $error): ActionRefused
    {
        return match (true) {
            $error instanceof ActionRefused => $error,
            $error instanceof ValidationException => new ActionRefused((string) collect($error->errors())->flatten()->first()),
            $error instanceof AuthorizationException => new ActionRefused('You can’t change that in this Paperwork record.'),
            $error instanceof HttpException && $error->getStatusCode() === 409 => new ActionRefused($error->getMessage() ?: 'Your Paperwork plan changed meanwhile. Ask again.'),
            default => throw $error,
        };
    }
}
