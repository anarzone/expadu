<?php

namespace App\Console\Commands\Bureaucracy;

use App\Enums\DeadlineType;
use App\Enums\Urgency;
use App\Models\Task;
use App\Profile\ProfileEngine;
use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

/**
 * Idempotent YAML → DB seeder for the bureaucracy task catalogue.
 *
 * Reads files at database/seeders/data/bureaucracy/{situation}.yaml and
 * upserts Task rows keyed by the stable `key` slug. Re-run after every
 * content edit — non-destructive (existing user_tasks are preserved).
 *
 * Trust rules:
 * - `verified_at` is read from YAML and is ONLY ever set there by a human
 *   after checking the official source. The importer never invents it.
 * - tasks without `is_published: true`... default to published; set
 *   `is_published: false` to hide a task whose facts can't be verified yet.
 *
 * Dependency rules:
 * - `depends_on` lists other task keys. The full set (all files) is
 *   topologically validated before anything is written; a cycle or an
 *   unknown key aborts the entire import.
 *
 * YAML shape per file:
 *
 *   situation: non_eu_employee        # string or array
 *   tasks:
 *     - key: anmeldung                # stable slug, unique across catalogue
 *       title: Register your address (Anmeldung)
 *       type: task                    # task (default) | info (good-to-know card)
 *       eu_filter: null               # eu_only | non_eu_only | null (both)
 *       depends_on: []                # task keys that must be done first
 *       verified_at: 2026-06-10       # HUMAN-set date, omit if unverified
 *       is_published: true
 *       description: ...
 *       phase: arrival
 *       urgency: critical
 *       deadline_type: days_since_arrival
 *       deadline_days: 14
 *       recurrence_months: null
 *       documents_required: [Passport, Rental contract]
 *       links: [https://...]
 *       how_to_steps:
 *         - title: Book an appointment
 *           body: Use the city's online booking portal.
 *           link: https://termine.stadt-koeln.de/...
 *       booking_service_key: anmeldung
 */
class ImportTasksCommand extends Command
{
    protected $signature = 'bureaucracy:import-tasks {file? : Specific YAML file (default: import all)} {--dry-run : Show what would change without writing} {--prune : Delete tasks whose key left the catalogue (full imports only)}';

    protected $description = 'Upsert bureaucracy tasks from authored YAML files';

    public function handle(): int
    {
        $files = $this->resolveFiles();
        if (empty($files)) {
            $this->error('No YAML files found at database/seeders/data/bureaucracy/');

            return self::FAILURE;
        }

        // Parse everything first — the DAG is validated across the whole
        // catalogue before a single row is written.
        $entries = [];
        $skippedFiles = 0;

        foreach ($files as $file) {
            $this->line("→ {$file}");
            $parsed = $this->parseFile($file);
            if ($parsed === null) {
                $skippedFiles++;

                continue;
            }

            $situations = is_array($parsed['situation'])
                ? $parsed['situation']
                : [$parsed['situation']];

            foreach ($parsed['tasks'] as $taskData) {
                $entries[] = ['situations' => $situations, 'data' => $taskData];
            }
        }

        if (! $this->validateKeys($entries) || ! $this->validateDag($entries)) {
            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;

        foreach ($entries as $entry) {
            $result = $this->upsertTask($entry['situations'], $entry['data']);
            if ($result === 'created') {
                $created++;
            } elseif ($result === 'updated') {
                $updated++;
            }
        }

        if ($this->option('prune')) {
            $this->pruneStaleTasks($entries);
        }

        $this->info("Done. created={$created} updated={$updated} skipped_files={$skippedFiles}");

        return self::SUCCESS;
    }

    /**
     * Remove tasks that are not part of the catalogue: keyed rows whose key
     * left the YAML (moved/renamed) AND keyless rows (legacy seeds, ad-hoc
     * creations). The YAML files are the single source of truth — anything
     * not in them would duplicate or contradict catalogue cards. Cascades
     * to user_tasks, so it only runs on full-catalogue imports and lists
     * everything it deletes.
     *
     * @param  list<array{situations: array<int, string>, data: array<string, mixed>}>  $entries
     */
    private function pruneStaleTasks(array $entries): void
    {
        if (is_string($this->argument('file')) && $this->argument('file') !== '') {
            $this->warn('  --prune ignored: only allowed on full-catalogue imports');

            return;
        }

        $keys = array_column(array_column($entries, 'data'), 'key');

        $stale = Task::query()
            ->where(function ($query) use ($keys) {
                $query->whereNull('key')->orWhereNotIn('key', $keys);
            })
            ->get();

        foreach ($stale as $task) {
            $label = $task->key ?? "(keyless) {$task->title}";
            $progress = $task->userTasks()->count();
            if ($this->option('dry-run')) {
                $this->line("  [dry] would prune: {$label} ({$progress} user task(s))");

                continue;
            }
            $task->delete();
            $this->warn("  pruned: {$label} ({$progress} user task(s) removed)");
        }
    }

    /**
     * Every task needs a unique key; duplicates across files abort.
     *
     * @param  list<array{situations: array<int, string>, data: array<string, mixed>}>  $entries
     */
    private function validateKeys(array $entries): bool
    {
        $seen = [];

        foreach ($entries as $entry) {
            $key = $entry['data']['key'] ?? null;
            if (! is_string($key) || $key === '') {
                $this->error('  ABORT — task missing `key`: '.($entry['data']['title'] ?? '(untitled)'));

                return false;
            }
            if (isset($seen[$key])) {
                $this->error("  ABORT — duplicate task key `{$key}`");

                return false;
            }
            $seen[$key] = true;
        }

        return true;
    }

    /**
     * Topological validation of depends_on across the whole catalogue:
     * unknown keys and cycles abort the import.
     *
     * @param  list<array{situations: array<int, string>, data: array<string, mixed>}>  $entries
     */
    private function validateDag(array $entries): bool
    {
        $graph = [];
        foreach ($entries as $entry) {
            $graph[$entry['data']['key']] = array_values((array) ($entry['data']['depends_on'] ?? []));
        }

        foreach ($graph as $key => $deps) {
            foreach ($deps as $dep) {
                if (! isset($graph[$dep])) {
                    $this->error("  ABORT — `{$key}` depends on unknown key `{$dep}`");

                    return false;
                }
            }
        }

        // Kahn's algorithm: if we can't consume every node, there's a cycle.
        $inDegree = array_fill_keys(array_keys($graph), 0);
        foreach ($graph as $deps) {
            foreach ($deps as $dep) {
                $inDegree[$dep]++;
            }
        }

        $queue = array_keys(array_filter($inDegree, fn ($d) => $d === 0));
        $visited = 0;

        while ($queue !== []) {
            $node = array_pop($queue);
            $visited++;
            foreach ($graph[$node] as $dep) {
                if (--$inDegree[$dep] === 0) {
                    $queue[] = $dep;
                }
            }
        }

        if ($visited !== count($graph)) {
            $cycleCandidates = implode(', ', array_keys(array_filter($inDegree, fn ($d) => $d > 0)));
            $this->error("  ABORT — dependency cycle involving: {$cycleCandidates}");

            return false;
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private function resolveFiles(): array
    {
        $arg = $this->argument('file');
        if (is_string($arg) && $arg !== '') {
            return [$this->resolvePath($arg)];
        }

        $dir = database_path('seeders/data/bureaucracy');
        if (! is_dir($dir)) {
            return [];
        }

        return array_values(array_filter(
            glob($dir.'/*.yaml') ?: [],
            fn ($f) => is_file($f)
        ));
    }

    private function resolvePath(string $arg): string
    {
        if (str_starts_with($arg, '/') || file_exists($arg)) {
            return $arg;
        }

        return database_path("seeders/data/bureaucracy/{$arg}");
    }

    /**
     * @return array{situation: string|array<int, string>, tasks: array<int, array<string, mixed>>}|null
     */
    private function parseFile(string $path): ?array
    {
        if (! file_exists($path)) {
            $this->warn("  skipped — file not found: {$path}");

            return null;
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (\Throwable $e) {
            $this->error("  YAML parse error in {$path}: {$e->getMessage()}");

            return null;
        }

        if (! is_array($data) || ! isset($data['situation'], $data['tasks'])) {
            $this->warn('  skipped — missing required keys (situation, tasks)');

            return null;
        }

        return $data;
    }

    /**
     * @param  array<int, string>  $situations
     * @param  array<string, mixed>  $data
     */
    private function upsertTask(array $situations, array $data): string
    {
        $key = (string) $data['key'];
        $title = (string) ($data['title'] ?? '');
        if ($title === '') {
            $this->warn("  skipped — task `{$key}` missing title");

            return 'skipped';
        }

        $payload = [
            'title' => $title,
            // task = actionable · info = good-to-know card · wait = arrives
            // at the user (post) · decision = completable by deciding.
            'type' => in_array($data['type'] ?? 'task', ['task', 'info', 'wait', 'decision'], true)
                ? ($data['type'] ?? 'task')
                : 'task',
            'description' => $data['description'] ?? null,
            'situation' => $situations,
            'eu_filter' => $data['eu_filter'] ?? null,
            'applies_if' => $this->compileAppliesIf($situations, $data),
            'decision_options' => $data['decision_options'] ?? null,
            // Dormant until the named life event is recorded by the user.
            'trigger_event' => in_array($data['trigger'] ?? null, ProfileEngine::LIFE_EVENTS, true)
                ? $data['trigger']
                : null,
            'phase' => $data['phase'] ?? null,
            'depends_on' => array_values((array) ($data['depends_on'] ?? [])),
            'urgency' => $this->normalizeUrgency($data['urgency'] ?? 'medium'),
            'deadline_type' => $this->normalizeDeadlineType($data['deadline_type'] ?? 'none'),
            'deadline_days' => isset($data['deadline_days']) ? (int) $data['deadline_days'] : null,
            'recurrence_months' => isset($data['recurrence_months']) ? (int) $data['recurrence_months'] : null,
            'documents_required' => $data['documents_required'] ?? [],
            'links' => $data['links'] ?? [],
            'how_to_steps' => $data['how_to_steps'] ?? [],
            'booking_service_key' => $data['booking_service_key'] ?? null,
            // Human-verified only: the importer copies the YAML value verbatim.
            'verified_at' => $data['verified_at'] ?? null,
            'is_published' => (bool) ($data['is_published'] ?? true),
        ];

        // Volatile numbers ({{figure:key}}) resolve from config at import.
        foreach (['title', 'description', 'documents_required', 'how_to_steps', 'decision_options'] as $field) {
            $payload[$field] = $this->substituteFigures($payload[$field] ?? null);
        }
        $title = $payload['title'];

        if ($this->option('dry-run')) {
            $this->line("  [dry] would upsert: {$key}");

            return 'skipped';
        }

        $existing = Task::query()->where('key', $key)->first()
            // Legacy fallback: first import after the v2 migration matches
            // pre-key rows by title+situation so they gain keys in place.
            ?? Task::query()
                ->whereNull('key')
                ->where('title', $title)
                ->whereJsonContains('situation', $situations[0])
                ->first();

        if ($existing) {
            $existing->fill([...$payload, 'key' => $key])->save();
            $this->line("  updated: {$key}");

            return 'updated';
        }

        Task::create([...$payload, 'key' => $key]);
        $this->line("  created: {$key}");

        return 'created';
    }

    /**
     * Compile the readable branch shorthand into applies_if predicate groups.
     *
     * Each `situation:` entry maps through ProfileEngine::BRANCH_PREDICATES
     * to one AND-group (any group matching → task applies). eu_filter and an
     * explicit YAML `applies_if:` map merge INTO every group — so authors
     * write `applies_if: {entry_mode: [d_visa, visa_free]}` to gate a task
     * without repeating the branch conditions.
     *
     * @param  array<int, string>  $situations
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function compileAppliesIf(array $situations, array $data): array
    {
        $extra = (array) ($data['applies_if'] ?? []);

        $euFilter = $data['eu_filter'] ?? null;
        if ($euFilter === 'eu_only') {
            $extra['citizenship_group'] = 'eu';
        } elseif ($euFilter === 'non_eu_only') {
            $extra['citizenship_group'] = 'non_eu';
        }

        $groups = [];
        foreach ($situations as $branch) {
            $base = ProfileEngine::BRANCH_PREDICATES[$branch] ?? null;
            if ($base === null) {
                $this->warn("  unknown branch `{$branch}` — applies_if group skipped");

                continue;
            }
            // Explicit conditions override the branch defaults (e.g. a task
            // narrowing permit_track inside a multi-track file).
            $groups[] = array_merge($base, $extra);
        }

        return $groups;
    }

    /**
     * Substitute {{figure:key}} placeholders with the current value from
     * config/bureaucracy_figures.php — volatile official numbers live in
     * one annually-updated file, not scattered through content.
     */
    private function substituteFigures(mixed $value): mixed
    {
        if (is_string($value)) {
            return preg_replace_callback('/\{\{figure:([a-z0-9_]+)\}\}/', function (array $m): string {
                $figure = config("bureaucracy_figures.{$m[1]}");
                if ($figure === null) {
                    $this->error("  ABORT-worthy: unknown figure `{$m[1]}` left verbatim");

                    return $m[0];
                }

                return $figure['value'];
            }, $value);
        }

        if (is_array($value)) {
            return array_map(fn ($v) => $this->substituteFigures($v), $value);
        }

        return $value;
    }

    private function normalizeUrgency(string $value): string
    {
        return match (strtolower($value)) {
            'critical' => Urgency::Critical->value,
            'high' => Urgency::High->value,
            'low' => Urgency::Low->value,
            default => Urgency::Medium->value,
        };
    }

    private function normalizeDeadlineType(string $value): string
    {
        $normalized = strtolower(str_replace('-', '_', $value));

        return match ($normalized) {
            'days_since_arrival' => DeadlineType::DaysSinceArrival->value,
            'days_since_move_in' => DeadlineType::DaysSinceMoveIn->value,
            'permit_window' => DeadlineType::PermitWindow->value,
            'days_since_event' => DeadlineType::DaysSinceEvent->value,
            default => DeadlineType::None->value,
        };
    }
}
