<?php

namespace App\Console\Commands\Bureaucracy;

use App\Bureaucracy\BureaucracyPersonas;
use App\Bureaucracy\Catalogue\CoverageManifest;
use App\Bureaucracy\PathGenerator;
use App\Bureaucracy\RuleSourcePolicy;
use App\Models\Task;
use App\Profile\Applicability;
use App\Profile\Profile;
use App\Profile\ProfileEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * A demo-cum-audit of the bureaucracy path engine: runs the REAL ProfileEngine
 * + PathGenerator over every reachable expat persona and reports the exact task
 * list each synthetic profile matches, then checks legacy catalogue structure.
 * This is not a legal coverage report or a projection of what users see:
 *
 *   - definite mismatches and missing prerequisites are structural errors;
 *   - unanswered prerequisites remain separately visible, not false mismatches;
 *   - every published task is reachable or awaits an answer in the audit sweep;
 *   - every unreviewed task that can be Unknown has a teaser for the attribute it
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
    protected $signature = 'bureaucracy:coverage {--full : Retained for compatibility; the audit always sweeps every modifier} {--manifest : Read-only JSON inventory of live v2 coverage and review gaps} {--fail-on-gap : Exit non-zero if any invariant or reported coverage gap remains (CI gate)}';

    protected $description = 'Audit legacy catalogue structure; use --manifest for canonical source and coverage gaps';

    /**
     * @var Collection<string, Task>
     */
    private Collection $tasks;

    /**
     * Keys of the rules that actually reach the verified plan. Taken from the
     * `authoritative()` scope itself rather than re-checked here — the approval
     * window has a dozen conditions and a second copy would drift.
     *
     * @var array<string, int>
     */
    private array $authoritative = [];

    /** @var array<string, true> */
    private array $unresolvedDependencies = [];

    public function __construct(
        private ProfileEngine $engine,
        private PathGenerator $paths,
        private RuleSourcePolicy $sourcePolicy,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('manifest')) {
            $report = app(CoverageManifest::class)->current();
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $hasGaps = $report['counts']['total'] === 0 || $report['unidentified_units'] !== []
                || collect($report['units'])->contains(fn ($unit) => $unit['coverage'] !== 'covered' || $unit['gaps'] !== []);

            return $this->option('fail-on-gap') && $hasGaps ? self::FAILURE : self::SUCCESS;
        }

        $this->unresolvedDependencies = [];
        $this->tasks = Task::query()
            ->where('is_published', true)
            ->get()
            ->keyBy('key');

        $this->authoritative = Task::query()
            ->authoritative()
            ->whereNotNull('key')
            ->pluck('key')
            ->flip()
            ->all();

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
            // A missing edge is invalid even behind unanswered or dormant
            // conditions; the persona loop only checks definite mismatches.
            foreach ((array) ($task->depends_on ?? []) as $dependency) {
                if (! is_string($dependency) || ! $this->tasks->has($dependency)) {
                    $label = is_string($dependency) ? $dependency : '[invalid key]';
                    $violations[] = "BROKEN DEP — `{$key}` needs `{$label}`, which is missing or unpublished.";
                }
            }
        }

        // The matrix: one row per canonical persona, so every label reports the
        // seed's own entry_mode rather than a modifier variant's numbers.
        $rows = [];
        $unverified = [];
        foreach ($this->canonicalPersonas() as $persona) {
            $verdict = $this->evaluate($this->profileFor($persona));
            $this->accumulate($verdict, $reachable, $waitingOn);
            $rows[] = $this->summariseRow($persona, $verdict, $violations, $unverified);
        }

        // The audit: widen across every modifier so reachability and dependency
        // integrity are judged against the whole space the engine can represent.
        foreach ($this->sweepPersonas() as $persona) {
            $verdict = $this->evaluate($this->profileFor($persona));
            $this->accumulate($verdict, $reachable, $waitingOn);
            $ignored = [];
            $this->summariseRow($persona, $verdict, $violations, $ignored);
        }

        $dead = $this->deadTasks($reachable, $waitingOn);
        $orphanTeasers = $this->orphanTeasers($reachable, $waitingOn);

        $this->renderMatrix($rows);
        $this->renderVerifiedPlanAdvisory($unverified);
        if ($this->unresolvedDependencies !== []) {
            $this->newLine();
            $this->warn('Unresolved dependencies — these prerequisites need answers, not an assumed result:');
            foreach (array_keys($this->unresolvedDependencies) as $dependency) {
                $this->line("  · {$dependency}");
            }
        }
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
    private function summariseRow(array $persona, array $verdict, array &$violations, array &$unverified): array
    {
        $yes = $verdict['yes'];
        $applicable = array_flip($yes);
        $label = $persona['label'];

        $hasAnmeldung = collect($yes)->contains(
            fn (string $key) => str_ends_with($key, '.anmeldung')
                || ($this->tasks[$key]->booking_service_key ?? null) === 'anmeldung'
        );
        // A broad persona label cannot establish that registration or a new
        // permit is required. Actual facts and reviewed canonical rules do that.
        $hasPermit = collect($yes)->contains(
            fn (string $key) => str_contains($key, 'permit') || str_contains($key, 'aufenthalt')
        );

        // Separate source-approved synthetic matches from unreviewed ones.
        // Neither count substitutes for the canonical person assessment.
        $approved = collect($yes)->filter(
            fn (string $key) => isset($this->authoritative[$key])
                && $this->sourcePolicy->persistedErrors($this->tasks[$key]) === []
        );
        $approvedAnmeldung = $approved->contains(
            fn (string $key) => str_ends_with($key, '.anmeldung')
                || ($this->tasks[$key]->booking_service_key ?? null) === 'anmeldung'
        );

        if ($hasAnmeldung && ! $approvedAnmeldung) {
            $unverified[] = $label;
        }

        // Only a definite mismatch is a broken dependency. Missing answers
        // remain an explicit unresolved prerequisite, not proof of impossibility.
        foreach ($yes as $key) {
            foreach ((array) ($this->tasks[$key]->depends_on ?? []) as $dep) {
                if (! is_string($dep)) {
                    // The catalogue-wide integrity pass already reports this.
                    continue;
                }
                if (array_key_exists($dep, $verdict['unknown'])) {
                    $this->unresolvedDependencies["`{$key}` awaits `{$dep}`"] = true;
                } elseif (! isset($applicable[$dep])) {
                    $violations[] = "BROKEN DEP — {$label}: `{$key}` needs `{$dep}`, which does not apply to this persona.";
                }
            }
        }

        return [
            'Persona' => $label,
            'Branch' => $this->engineBranchLabel($persona),
            'Tasks' => (string) count($yes),
            'Verified' => $approved->isEmpty() ? '0 ✗' : (string) $approved->count(),
            'Anmeldung' => $approvedAnmeldung ? '✓' : ($hasAnmeldung ? 'legacy' : '✗'),
            'Permit' => $hasPermit ? '✓' : '—',
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
            ['Persona', 'Branch', 'Tasks', 'Verified', 'Anmeldung', 'Permit', 'Teasers'],
            $rows,
        );
        $this->line('  Tasks = published cards reachable · Verified = of those, how many pass Task::authoritative()');
        $this->line('  Synthetic structural audit only; this is not the user plan or proof of legal coverage.');
        $this->line('  Use bureaucracy:coverage --manifest for canonical publication and remaining criterion gaps.');
    }

    /**
     * Branches whose address registration exists only as unreviewed content.
     *
     * Structural success must not imply publication readiness. The canonical
     * manifest is the strict source/criteria audit; this legacy table is not.
     *
     * @param  list<string>  $unverified
     */
    private function renderVerifiedPlanAdvisory(array $unverified): void
    {
        if ($unverified === []) {
            return;
        }

        $this->newLine();
        $this->warn('Verified-plan gap — reachable, but not through reviewed content:');

        foreach (array_unique($unverified) as $label) {
            $this->line("  · {$label} — reaches an Anmeldung task, but no APPROVED one.");
        }

        $this->line('  Unreviewed matches are not user-facing guidance. Confirmed answers may also');
        $this->line('  be missing; the canonical manifest and plan distinguish those states.');
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
            // Deliberately scoped to what was actually checked. The old wording
            // was a flat "No gaps", printed directly under an advisory saying
            // most branches have an empty verified plan.
            $this->info('✓ No STRUCTURAL gaps: no definite broken dependencies or unaskable tasks in this synthetic sweep.');
            $this->line('  Unanswered prerequisites remain unresolved; this does not establish legal coverage or publication readiness.');

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
