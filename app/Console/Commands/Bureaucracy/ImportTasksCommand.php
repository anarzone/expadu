<?php

namespace App\Console\Commands\Bureaucracy;

use App\Enums\DeadlineType;
use App\Enums\Urgency;
use App\Models\Task;
use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

/**
 * Idempotent YAML → DB seeder for the bureaucracy task catalogue.
 *
 * Reads files at database/seeders/data/bureaucracy/{situation}.yaml and upserts
 * Task rows keyed by (title + situation). Designed to be re-run after every
 * content edit — the run is non-destructive (existing user_tasks are preserved),
 * so authoring loops as: AI draft → edit YAML → re-import → repeat.
 *
 * YAML shape per file:
 *
 *   situation: non_eu_employee        # string or array
 *   tasks:
 *     - title: Anmeldung
 *       description: Register your address at the Bürgeramt.
 *       phase: arrival                # optional grouping label
 *       urgency: critical             # critical|high|medium|low
 *       deadline_type: days_since_arrival   # days_since_arrival|none
 *       deadline_days: 14
 *       recurrence_months: null       # null = one-shot; integer = repeat
 *       documents_required:
 *         - Passport
 *         - Rental contract
 *       links:
 *         - https://...
 *       how_to_steps:
 *         - title: Book an appointment
 *           body: Use the city's online booking portal.
 *           link: https://termine.stadt-koeln.de/...
 *         - title: Gather documents
 *           body: ...
 *       booking_service_key: anmeldung   # optional, links to BuergeramtService
 */
class ImportTasksCommand extends Command
{
    protected $signature = 'bureaucracy:import-tasks {file? : Specific YAML file (default: import all)} {--dry-run : Show what would change without writing}';

    protected $description = 'Upsert bureaucracy tasks from authored YAML files';

    public function handle(): int
    {
        $files = $this->resolveFiles();
        if (empty($files)) {
            $this->error('No YAML files found at database/seeders/data/bureaucracy/');

            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($files as $file) {
            $this->line("→ {$file}");
            $parsed = $this->parseFile($file);
            if ($parsed === null) {
                $skipped++;

                continue;
            }

            $situations = is_array($parsed['situation'])
                ? $parsed['situation']
                : [$parsed['situation']];

            foreach ($parsed['tasks'] as $taskData) {
                $result = $this->upsertTask($situations, $taskData);
                if ($result === 'created') {
                    $created++;
                } elseif ($result === 'updated') {
                    $updated++;
                }
            }
        }

        $this->info("Done. created={$created} updated={$updated} skipped_files={$skipped}");

        return self::SUCCESS;
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
        $title = (string) ($data['title'] ?? '');
        if ($title === '') {
            $this->warn('  skipped — task missing title');

            return 'skipped';
        }

        $payload = [
            'title' => $title,
            'description' => $data['description'] ?? null,
            'situation' => $situations,
            'phase' => $data['phase'] ?? null,
            'urgency' => $this->normalizeUrgency($data['urgency'] ?? 'medium'),
            'deadline_type' => $this->normalizeDeadlineType($data['deadline_type'] ?? 'none'),
            'deadline_days' => isset($data['deadline_days']) ? (int) $data['deadline_days'] : null,
            'recurrence_months' => isset($data['recurrence_months']) ? (int) $data['recurrence_months'] : null,
            'documents_required' => $data['documents_required'] ?? [],
            'links' => $data['links'] ?? [],
            'how_to_steps' => $data['how_to_steps'] ?? [],
            'booking_service_key' => $data['booking_service_key'] ?? null,
        ];

        if ($this->option('dry-run')) {
            $this->line("  [dry] would upsert: {$title}");

            return 'skipped';
        }

        $existing = Task::query()
            ->where('title', $title)
            ->whereJsonContains('situation', $situations[0])
            ->first();

        if ($existing) {
            $existing->fill($payload)->save();
            $this->line("  updated: {$title}");

            return 'updated';
        }

        Task::create($payload);
        $this->line("  created: {$title}");

        return 'created';
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
            default => DeadlineType::None->value,
        };
    }
}
