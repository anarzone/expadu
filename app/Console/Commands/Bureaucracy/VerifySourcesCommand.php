<?php

namespace App\Console\Commands\Bureaucracy;

use App\Bureaucracy\Verification\ClaimCheck;
use App\Bureaucracy\Verification\Escalations;
use App\Bureaucracy\Verification\Figures;
use App\Bureaucracy\Verification\SourceCheckRecorder;
use App\Bureaucracy\Verification\SourcePageFetcher;
use App\Bureaucracy\Verification\SourceText;
use App\Models\Task;
use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

/**
 * Re-checks every card that carries source claims against the live official pages.
 *
 * Report-only: results are recorded and failures escalated, but nothing here
 * publishes or withdraws a card. Network access is required, so this runs on a
 * schedule and never in the test suite.
 */
class VerifySourcesCommand extends Command
{
    protected $signature = 'bureaucracy:verify-sources {--key=* : Only these card keys} {--offline : Skip fetching; check claim structure, coverage and figures only}';

    protected $description = 'Check each card\'s source quotes against the official pages and escalate failures';

    public function handle(ClaimCheck $checks, SourcePageFetcher $fetcher, SourceCheckRecorder $reviews, Escalations $escalations): int
    {
        $query = Task::query()->where('is_published', true)->whereNotNull('claims')->orderBy('key');
        if ($this->option('key') !== []) {
            $query->whereIn('key', $this->option('key'));
        }
        $tasks = $query->get();
        if ($tasks->isEmpty()) {
            $this->info('No published cards carry source claims yet.');

            return self::SUCCESS;
        }
        $topics = $this->topics();
        $pages = [];
        $rows = [];
        foreach ($tasks as $task) {
            $card = $task->attributesToArray();
            if ($this->option('offline')) {
                $failures = $checks->offlineErrors($card);
                $rows[] = [$task->key, $failures === [] ? 'ok' : 'failed', implode("\n", $failures)];

                continue;
            }
            foreach ($task->legal_sources ?? [] as $source) {
                $url = $source['url'] ?? null;
                if (is_string($url) && ! isset($pages[$url])) {
                    $pages[$url] = $fetcher->fetch($url);
                }
            }
            $result = $checks->check($card, $pages);
            $reviews->record($task, $result);
            if ($result['outcome'] === 'failed') {
                $escalations->raise('source_check_failed', $task->key, $this->severity($task, $topics[$task->key] ?? null),
                    "\"{$task->title}\" no longer matches its official source.", ['failures' => $result['failures']]);
            } elseif ($result['outcome'] === 'passed') {
                $escalations->resolve('source_check_failed', $task->key);
            }
            $rows[] = [$task->key, $result['outcome'], implode("\n", [...$result['failures'], ...array_map(fn ($url) => "unreachable: {$url}", $result['unreachable'])])];
        }
        $this->table(['Card', 'Result', 'Details'], $rows);

        return collect($rows)->contains(fn ($row) => $row[1] === 'failed') ? self::FAILURE : self::SUCCESS;
    }

    /** High severity reaches the owner: residence matters, dated deadlines, urgent cards and money. */
    private function severity(Task $task, ?string $topic): string
    {
        $money = collect(app(ClaimCheck::class)->visibleText($task->attributesToArray()))
            ->contains(fn ($text) => collect(Figures::in(SourceText::key($text)))->contains(fn ($figure) => $figure['kind'] === 'money'));

        return $topic === 'residence' || $task->deadline_days !== null || $money
            || in_array($task->urgency?->value, ['critical', 'high'], true) ? Escalations::High : Escalations::Normal;
    }

    /** @return array<string, string|null> */
    private function topics(): array
    {
        $entries = Yaml::parseFile(database_path('seeders/data/bureaucracy/schema/process-map.yaml'))['entries'] ?? [];

        return array_map(fn ($entry) => is_array($entry) ? ($entry['topic'] ?? null) : null, $entries);
    }
}
