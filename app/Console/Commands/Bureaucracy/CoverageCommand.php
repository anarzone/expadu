<?php

namespace App\Console\Commands\Bureaucracy;

use App\Bureaucracy\BureaucracyPersonas;
use App\Bureaucracy\PathGenerator;
use App\Bureaucracy\RuleSourcePolicy;
use App\Enums\Situation;
use App\Models\Task;
use App\Profile\Applicability;
use App\Profile\Profile;
use App\Profile\ProfileEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * A demo-cum-audit of the bureaucracy path engine: runs the REAL ProfileEngine
 * + PathGenerator over every reachable expat persona and reports the exact task
 * list each one sees, then checks the structural invariants that must hold for
 * the catalogue to be "complete":
 *
 *   1. every persona gets the Anmeldung root (nothing downstream works without it);
 *   2. every non-EU permit-bearing persona gets a residence-permit task;
 *   3. no applicable task depends_on a task that is NOT applicable to the same
 *      persona (that task would be blocked forever);
 *   4. every published task is reachable by at least one persona (no dead cards);
 *   5. every task that can be Unknown has a teaser question for the attribute it
 *      waits on (otherwise it is silently hidden, never asked).
 *
 * Read-only: it materialises no rows — personas are in-memory User instances the
 * engine reads like any other.
 *
 * Display breadth and audit breadth are deliberately separate. The printed matrix
 * stays canonical — one honest row per persona seed, carrying that seed's own
 * entry_mode — while invariants 3-5 ALWAYS sweep the full housing / licence /
 * entry-mode / life-event cross-product. Reachability is only meaningful over
 * that cross-product: life-event tasks are dormant until their trigger fires, and
 * a licence task needs a licence-bearing persona, so a narrow sweep would report
 * both as dead cards. Auditing wide unconditionally keeps `--fail-on-gap` honest
 * in any invocation.
 */
class CoverageCommand extends Command
{
    protected $signature = 'bureaucracy:coverage {--full : Retained for compatibility; the audit always sweeps every modifier} {--fail-on-gap : Exit non-zero if any invariant is violated (CI gate)}';

    protected $description = 'Run the path engine over every expat persona and report task coverage + gaps';

    /**
     * @var Collection<string, Task>
     */
    private Collection $tasks;

    public function __construct(
        private ProfileEngine $engine,
        private PathGenerator $paths,
        private RuleSourcePolicy $sourcePolicy,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->tasks = Task::query()
            ->where('is_published', true)
            ->get()
            ->keyBy('key');

        if ($this->tasks->whereNull('key')->isNotEmpty()) {
            $this->warn('Some published tasks have no `key` — they are excluded from dependency checks.');
        }

        $reachable = [];   // task key => true (applicable to at least one persona)
        $waitingOn = [];   // task key => [attribute => true] genuinely unresolved
        $violations = [];  // list<string> human-readable invariant failures

        foreach ($this->tasks as $key => $task) {
            foreach ($this->sourcePolicy->persistedErrors($task) as $error) {
                $violations[] = "SOURCE REVIEW — `{$key}`: {$error}.";
            }
        }

        // The matrix: one row per canonical persona, so every label reports the
        // seed's own entry_mode rather than a modifier variant's numbers.
        $rows = [];
        foreach ($this->canonicalPersonas() as $persona) {
            $verdict = $this->evaluate($this->profileFor($persona));
            $this->accumulate($verdict, $reachable, $waitingOn);
            $rows[] = $this->summariseRow($persona, $verdict, $violations);
        }

        // The audit: widen across every modifier so reachability and dependency
        // integrity are judged against the whole space the engine can represent.
        foreach ($this->sweepPersonas() as $persona) {
            $verdict = $this->evaluate($this->profileFor($persona));
            $this->accumulate($verdict, $reachable, $waitingOn);
            $this->summariseRow($persona, $verdict, $violations);
        }

        $dead = $this->deadTasks($reachable, $waitingOn);
        $orphanTeasers = $this->orphanTeasers($reachable, $waitingOn);

        $this->renderMatrix($rows);
        $this->renderGaps($dead, $orphanTeasers, $violations);

        // A silently-hidden task is invariant 5: it prints a warning AND fails
        // the gate, otherwise a task nobody can ever be asked about ships green.
        $gaps = count($violations) + $dead->count() + count($orphanTeasers);

        if ($this->option('fail-on-gap') && $gaps > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Published tasks no persona reaches and no persona is ever asked about.
     *
     * @param  array<string, true>  $reachable
     * @param  array<string, array<string, true>>  $waitingOn
     * @return Collection<int, string>
     */
    private function deadTasks(array $reachable, array $waitingOn): Collection
    {
        return $this->tasks->keys()
            ->reject(fn (string $key) => isset($reachable[$key]) || isset($waitingOn[$key]))
            ->values();
    }

    /**
     * Teaser hygiene: a task that goes Unknown for someone, is reached by
     * nobody, and has no teaser question for the attribute it waits on would be
     * silently hidden — never applicable, never asked.
     *
     * @param  array<string, true>  $reachable
     * @param  array<string, array<string, true>>  $waitingOn
     * @return list<string>
     */
    private function orphanTeasers(array $reachable, array $waitingOn): array
    {
        $orphans = [];

        foreach ($waitingOn as $key => $attributes) {
            // Approved case rules use FactRegistry + QuestionSelector, not the
            // legacy ProfileEngine teaser map audited by this command.
            if ($this->tasks[$key]->review_status === RuleSourcePolicy::Approved) {
                continue;
            }

            // A task some persona reaches outright is never silently hidden.
            if (isset($reachable[$key])) {
                continue;
            }

            foreach (array_keys($attributes) as $attribute) {
                if (! isset(ProfileEngine::TEASER_QUESTIONS[$attribute])) {
                    $orphans[] = "`{$key}` waits on `{$attribute}` but no teaser question exists for it.";
                }
            }
        }

        return array_values(array_unique($orphans));
    }

    /**
     * The readable matrix: one row per branch × relevant entry_mode, each
     * keeping the seed's own entry_mode so the printed label matches its data.
     *
     * @return list<array<string, mixed>>
     */
    private function canonicalPersonas(): array
    {
        return array_map(
            fn (array $seed): array => [...$seed, 'housing' => 'long_term', 'license' => null, 'life' => []],
            BureaucracyPersonas::coverage(),
        );
    }

    /**
     * The audit sweep: every entry_mode × housing × licence × life-event
     * combination, so a conditionally-gated task cannot slip through unreached
     * and a dependency cannot hide behind a persona that happens to satisfy it.
     * Labels carry their modifiers so a violation names the exact variant.
     *
     * @return list<array<string, mixed>>
     */
    private function sweepPersonas(): array
    {
        $personas = [];
        foreach ($this->canonicalPersonas() as $base) {
            foreach (['d_visa', 'visa_free', 'has_permit'] as $entryMode) {
                foreach (['long_term', 'temporary'] as $housing) {
                    foreach (['eu', 'other', 'none', null] as $license) {
                        foreach ([[], ['child_born'], ['graduated'], ['child_born', 'graduated']] as $life) {
                            $modifiers = sprintf(
                                'entry=%s, housing=%s, licence=%s, life=%s',
                                $entryMode,
                                $housing,
                                $license ?? 'unset',
                                $life === [] ? 'none' : implode('+', $life),
                            );

                            $personas[] = [
                                ...$base,
                                'label' => "{$base['label']} [{$modifiers}]",
                                'entry_mode' => $entryMode,
                                'housing' => $housing,
                                'license' => $license,
                                'life' => $life,
                            ];
                        }
                    }
                }
            }
        }

        return $personas;
    }

    /**
     * Fold one persona's verdict into the catalogue-wide reachability tally.
     *
     * @param  array{yes: list<string>, unknown: array<string, list<string>>}  $verdict
     * @param  array<string, true>  $reachable
     * @param  array<string, array<string, true>>  $waitingOn
     */
    private function accumulate(array $verdict, array &$reachable, array &$waitingOn): void
    {
        foreach ($verdict['yes'] as $key) {
            $reachable[$key] = true;
        }

        foreach ($verdict['unknown'] as $key => $attributes) {
            foreach ($attributes as $attribute) {
                $waitingOn[$key][$attribute] = true;
            }
            $waitingOn[$key] ??= [];
        }
    }

    /**
     * @param  array<string, mixed>  $persona
     */
    private function profileFor(array $persona): Profile
    {
        return $this->engine->build(BureaucracyPersonas::userFor($persona));
    }

    /**
     * @return array{yes: list<string>, unknown: array<string, list<string>>}
     */
    private function evaluate(Profile $profile): array
    {
        $yes = [];
        $unknown = [];

        foreach ($this->tasks as $key => $task) {
            $verdict = $this->paths->applicability($task, $profile);
            if ($verdict === Applicability::Yes) {
                $yes[] = $key;
            } elseif ($verdict === Applicability::Unknown) {
                // Resolve the waited-on attributes against THIS persona's real
                // bag, exactly as PathGenerator::teasers() does. An empty bag
                // would mark branch-defining attributes (purpose, sponsor, …)
                // unknown even though every real user always has them.
                $unknown[$key] = Applicability::unknownAttributes($task->applies_if, $profile->attributes);
            }
        }

        return ['yes' => $yes, 'unknown' => $unknown];
    }

    /**
     * Build one matrix row and append this persona's invariant violations.
     *
     * @param  array<string, mixed>  $persona
     * @param  array{yes: list<string>, unknown: array<string, list<string>>}  $verdict
     * @param  list<string>  $violations
     * @return array<string, string>
     */
    private function summariseRow(array $persona, array $verdict, array &$violations): array
    {
        $yes = $verdict['yes'];
        $applicable = array_flip($yes);
        $label = $persona['label'];

        $hasAnmeldung = collect($yes)->contains(
            fn (string $key) => str_ends_with($key, '.anmeldung')
                || ($this->tasks[$key]->booking_service_key ?? null) === 'anmeldung'
        );
        if (! $hasAnmeldung) {
            $violations[] = "MISSING ANMELDUNG — {$label} has no address-registration root task.";
        }

        // Non-EU, purpose-bearing personas must reach a residence-permit task.
        $needsPermit = ! $persona['is_eu']
            && ! in_array($persona['situation'], [Situation::DigitalNomad, Situation::Other], true)
            && $persona['entry_mode'] !== 'has_permit';
        $hasPermit = collect($yes)->contains(
            fn (string $key) => str_contains($key, 'permit') || str_contains($key, 'aufenthalt')
        );
        if ($needsPermit && ! $hasPermit) {
            $violations[] = "MISSING PERMIT — {$label} is non-EU yet reaches no residence-permit task.";
        }

        // Dependency integrity: every applicable task's prerequisites must also
        // be applicable, else the task can never unblock.
        foreach ($yes as $key) {
            foreach ((array) ($this->tasks[$key]->depends_on ?? []) as $dep) {
                if (! isset($applicable[$dep])) {
                    $violations[] = "BROKEN DEP — {$label}: `{$key}` needs `{$dep}`, which does not apply to this persona.";
                }
            }
        }

        return [
            'Persona' => $label,
            'Branch' => $this->engineBranchLabel($persona),
            'Tasks' => (string) count($yes),
            'Anmeldung' => $hasAnmeldung ? '✓' : '✗',
            'Permit' => $hasPermit ? '✓' : ($needsPermit ? '✗' : '—'),
            'Teasers' => (string) count($verdict['unknown']),
        ];
    }

    /**
     * @param  array<string, mixed>  $persona
     */
    private function engineBranchLabel(array $persona): string
    {
        return $persona['path'] ?? strtolower($persona['situation']->name);
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function renderMatrix(array $rows): void
    {
        $this->newLine();
        $this->info('Bureaucracy coverage — real engine, every persona');
        $this->table(
            ['Persona', 'Branch', 'Tasks', 'Anmeldung', 'Permit', 'Teasers'],
            $rows,
        );
    }

    /**
     * @param  Collection<int, string>  $dead
     * @param  list<string>  $orphanTeasers
     * @param  list<string>  $violations
     */
    private function renderGaps(Collection $dead, array $orphanTeasers, array $violations): void
    {
        $this->newLine();
        if ($violations === [] && $dead->isEmpty() && $orphanTeasers === []) {
            $this->info('✓ No gaps. Every persona has a root + permit, all deps resolve, every task is reachable.');

            return;
        }

        if ($violations !== []) {
            $this->error('Invariant violations ('.count($violations).'):');
            foreach (array_unique($violations) as $line) {
                $this->line("  • {$line}");
            }
        }

        if ($dead->isNotEmpty()) {
            $this->newLine();
            $this->warn("Unreachable tasks ({$dead->count()}) — published but no persona ever sees them:");
            foreach ($dead as $key) {
                $this->line("  • {$key} — {$this->tasks[$key]->title}");
            }
        }

        if ($orphanTeasers !== []) {
            $this->newLine();
            $this->warn('Silently-hidden tasks ('.count($orphanTeasers).'):');
            foreach (array_unique($orphanTeasers) as $line) {
                $this->line("  • {$line}");
            }
        }
    }
}
