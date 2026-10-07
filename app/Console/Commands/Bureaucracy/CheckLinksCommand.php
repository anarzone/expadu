<?php

namespace App\Console\Commands\Bureaucracy;

use App\Bureaucracy\Catalogue\OfficialLinkProbe;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Every link the bureaucracy surface hands a user is an official source, and a
 * dead one is worse than none: it sends someone chasing a 404 for a deadline
 * that costs them their status.
 *
 * Owner review found two dead Quick Action links and a dead Kindergeld link
 * that had shipped unnoticed. Nothing was checking. This does — over the
 * network, so it is deliberately NOT part of the test suite; run it on a
 * schedule or before a content release.
 */
class CheckLinksCommand extends Command
{
    protected $signature = 'bureaucracy:check-links {--timeout=20} {--fail-on-dead : Exit non-zero if any link is unreachable}';

    protected $description = 'Check every published rule link and legal source still resolves';

    public function handle(): int
    {
        $targets = $this->targets();
        $this->info("Checking {$targets->count()} links…");

        $dead = [];
        $unverifiable = [];

        foreach ($targets as $url => $owners) {
            [$status, $reason] = $this->probe($url);
            $used = implode(', ', $owners);

            // A TLS or connection failure is NOT a dead link — locally it is
            // usually a missing CA bundle (cURL error 60), and reporting that
            // as "dead" trains everyone to ignore this command.
            if ($status === 0) {
                $unverifiable[] = ['url' => $url, 'reason' => $reason, 'owners' => $used];

                continue;
            }

            if ($status >= 200 && $status < 400) {
                continue;
            }

            $dead[] = ['url' => $url, 'status' => (string) $status, 'owners' => $used];
        }

        $this->newLine();

        if ($dead !== []) {
            $this->error('Dead links ('.count($dead).'):');
            $this->table(
                ['Status', 'URL', 'Used by'],
                array_map(fn (array $r): array => [$r['status'], $r['url'], $r['owners']], $dead),
            );
        }

        if ($unverifiable !== []) {
            $this->newLine();
            $this->warn('Could not verify ('.count($unverifiable).') — host review, redirect, connection or temporary response:');
            $this->table(
                ['Reason', 'URL', 'Used by'],
                array_map(fn (array $r): array => [$r['reason'], $r['url'], $r['owners']], $unverifiable),
            );
        }

        if ($dead === [] && $unverifiable === []) {
            $this->info('✓ Every link resolves.');
        }

        return ($this->option('fail-on-dead') && $dead !== []) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Collection<string, list<string>>
     */
    private function targets(): Collection
    {
        $targets = collect();

        foreach (Task::query()->where('is_published', true)->get() as $task) {
            $key = $task->key ?? "task#{$task->getKey()}";

            foreach ((array) ($task->links ?? []) as $link) {
                $url = is_array($link) ? ($link['url'] ?? null) : $link;
                $this->remember($targets, $url, $key);
            }

            foreach ((array) ($task->legal_sources ?? []) as $source) {
                $this->remember($targets, $source['url'] ?? null, "{$key} (legal source)");
            }
        }

        return $targets;
    }

    /**
     * @param  Collection<string, list<string>>  $targets
     */
    private function remember(Collection $targets, mixed $url, string $owner): void
    {
        if (! is_string($url) || ! str_starts_with($url, 'http')) {
            return;
        }

        $targets->put($url, [...$targets->get($url, []), $owner]);
    }

    /**
     * @return array{0: int, 1: string} status (0 when unreachable) and a short reason
     */
    private function probe(string $url): array
    {
        $result = app(OfficialLinkProbe::class)->check($url, (int) $this->option('timeout'));

        return [$result['status'] === 'unverifiable' ? 0 : $result['http_status'], $result['reason'] ?? ''];
    }
}
