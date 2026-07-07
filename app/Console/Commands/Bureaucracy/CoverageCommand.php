<?php

namespace App\Console\Commands\Bureaucracy;

use App\Bureaucracy\PathGenerator;
use App\Enums\Situation;
use App\Models\Task;
use App\Models\User;
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
 * engine reads like any other. `--full` widens the sweep across housing / licence
 * / life-event modifiers; the default run prints the canonical persona matrix.
 */
class CoverageCommand extends Command
{
    protected $signature = 'bureaucracy:coverage {--full : Sweep every housing/licence/life-event modifier, not just the canonical personas} {--fail-on-gap : Exit non-zero if any invariant is violated (CI gate)}';

    protected $description = 'Run the path engine over every expat persona and report task coverage + gaps';

    /**
     * @var Collection<string, Task>
     */
    private Collection $tasks;

    public function __construct(private ProfileEngine $engine, private PathGenerator $paths)
    {
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
        $askable = [];     // task key => true (surfaced as a teaser to at least one persona)
        $violations = [];  // list<string> human-readable invariant failures

        $rows = [];
        foreach ($this->personas() as $persona) {
            $profile = $this->profileFor($persona);
            $verdict = $this->evaluate($profile);

            foreach ($verdict['yes'] as $key) {
                $reachable[$key] = true;
            }
            foreach ($verdict['unknown'] as $key) {
                $askable[$key] = true;
            }

            $rows[] = $this->summariseRow($persona, $verdict, $violations);
        }

        $this->renderMatrix($rows);
        $this->renderGaps($reachable, $askable, $violations);

        $hardGaps = count($violations);
        $deadTasks = $this->tasks->keys()
            ->reject(fn (string $key) => isset($reachable[$key]) || isset($askable[$key]))
            ->count();

        if ($this->option('fail-on-gap') && ($hardGaps > 0 || $deadTasks > 0)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * The persona list. Default: one readable row per branch × relevant
     * entry_mode. `--full` multiplies in the cross-cutting modifiers so the
     * reachability / dependency sweep sees every gate.
     *
     * @return list<array<string, mixed>>
     */
    private function personas(): array
    {
        $seeds = [
            // label, situation, is_eu, path, entry_mode
            ['EU employee', Situation::EuEmployee, true, null, 'has_permit'],
            ['EU student', Situation::Student, true, null, 'has_permit'],
            ['EU freelancer (liberal)', Situation::Freelancer, true, null, 'has_permit'],
            ['EU freelancer (Gewerbe)', Situation::Freelancer, true, 'freelancer_gewerbe', 'has_permit'],
            ['Non-EU employee · standard · D-visa', Situation::NonEuEmployee, false, null, 'd_visa'],
            ['Non-EU employee · standard · visa-free', Situation::NonEuEmployee, false, null, 'visa_free'],
            ['Non-EU employee · standard · has permit', Situation::NonEuEmployee, false, null, 'has_permit'],
            ['Non-EU employee · Blue Card · D-visa', Situation::NonEuEmployee, false, 'non_eu_employee_blue_card', 'd_visa'],
            ['Non-EU employee · Chancenkarte · visa-free', Situation::NonEuEmployee, false, 'non_eu_employee_chancenkarte', 'visa_free'],
            ['Non-EU student · D-visa', Situation::Student, false, null, 'd_visa'],
            ['Non-EU freelancer (liberal §21.5) · D-visa', Situation::Freelancer, false, null, 'd_visa'],
            ['Non-EU freelancer (Gewerbe §21.1) · D-visa', Situation::Freelancer, false, 'freelancer_gewerbe', 'd_visa'],
            ['Family reunification → non-EU sponsor', Situation::FamilyReunification, false, null, 'd_visa'],
            ['Family reunification → German sponsor', Situation::FamilyReunification, false, 'family_reunification_of_german', 'd_visa'],
            ['Family reunification → EU sponsor', Situation::FamilyReunification, false, 'family_reunification_of_eu_citizen', 'visa_free'],
            ['Digital nomad / other · non-EU', Situation::DigitalNomad, false, null, 'visa_free'],
            ['Still figuring it out (Other) · EU', Situation::Other, true, null, 'has_permit'],
        ];

        $personas = [];
        foreach ($seeds as [$label, $situation, $isEu, $path, $entry]) {
            $base = [
                'label' => $label,
                'situation' => $situation,
                'is_eu' => $isEu,
                'path' => $path,
                'entry_mode' => $entry,
                'housing_status' => 'long_term',
                'license_country' => null,
                'life' => [],
            ];

            if (! $this->option('full')) {
                $personas[] = $base;

                continue;
            }

            // Widen the sweep: every entry_mode × housing × licence × life-event
            // combination so a conditionally-gated task cannot slip through
            // unreached, and a dependency on one cannot hide behind a persona
            // that happens to satisfy it.
            foreach (['d_visa', 'visa_free', 'has_permit'] as $entryMode) {
                foreach (['long_term', 'temporary'] as $housing) {
                    foreach (['eu', 'other', 'none', null] as $license) {
                        foreach ([[], ['child_born'], ['graduated'], ['child_born', 'graduated']] as $life) {
                            $personas[] = [
                                ...$base,
                                'label' => $label,
                                'entry_mode' => $entryMode,
                                'housing_status' => $housing,
                                'license_country' => $license,
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
     * @param  array<string, mixed>  $persona
     */
    private function profileFor(array $persona): Profile
    {
        $attributes = [
            'entry_mode' => $persona['entry_mode'],
            'housing_status' => $persona['housing_status'],
        ];
        if ($persona['license_country'] !== null) {
            $attributes['license_country'] = $persona['license_country'];
        }
        // Life events are date-valued: recording one wakes its gated tasks.
        foreach ($persona['life'] as $event) {
            $attributes["{$event}_at"] = now()->subMonth()->toDateString();
        }

        $user = new User([
            'situation' => $persona['situation']->value,
            'is_eu' => $persona['is_eu'],
            'bureaucracy_path' => $persona['path'],
            'arrival_date' => now()->subDays(10)->toDateString(),
            'veedel' => 'Altstadt-Nord',
            'profile_attributes' => $attributes,
            'interests' => [],
        ]);

        return $this->engine->build($user);
    }

    /**
     * @return array{yes: list<string>, unknown: list<string>}
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
                $unknown[] = $key;
            }
        }

        return ['yes' => $yes, 'unknown' => $unknown];
    }

    /**
     * Build one matrix row and append this persona's invariant violations.
     *
     * @param  array<string, mixed>  $persona
     * @param  array{yes: list<string>, unknown: list<string>}  $verdict
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
        if ($this->option('full')) {
            // In --full mode rows repeat per modifier; collapse to canonical.
            $seen = [];
            $rows = array_values(array_filter($rows, function (array $row) use (&$seen) {
                if (isset($seen[$row['Persona']])) {
                    return false;
                }
                $seen[$row['Persona']] = true;

                return true;
            }));
        }

        $this->newLine();
        $this->info('Bureaucracy coverage — real engine, every persona');
        $this->table(
            ['Persona', 'Branch', 'Tasks', 'Anmeldung', 'Permit', 'Teasers'],
            $rows,
        );
    }

    /**
     * @param  array<string, true>  $reachable
     * @param  array<string, true>  $askable
     * @param  list<string>  $violations
     */
    private function renderGaps(array $reachable, array $askable, array $violations): void
    {
        $dead = $this->tasks->keys()
            ->reject(fn (string $key) => isset($reachable[$key]) || isset($askable[$key]))
            ->values();

        // Teaser hygiene: a task that goes Unknown for someone but has no teaser
        // question for its waited-on attribute would be silently hidden.
        $orphanTeasers = [];
        foreach (array_keys($askable) as $key) {
            $unknownAttrs = Applicability::unknownAttributes($this->tasks[$key]->applies_if, []);
            foreach ($unknownAttrs as $attr) {
                if (! isset(ProfileEngine::TEASER_QUESTIONS[$attr]) && ! isset($reachable[$key])) {
                    $orphanTeasers[] = "`{$key}` waits on `{$attr}` but no teaser question exists for it.";
                }
            }
        }

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
