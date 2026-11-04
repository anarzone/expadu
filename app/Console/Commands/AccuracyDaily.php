<?php

namespace App\Console\Commands;

use App\Models\ComposerTrace;
use App\Models\Event;
use App\Models\NoticeBrief;
use App\Models\Task;
use App\Services\DisruptionService;
use App\Support\RedisLogger;
use App\Transit\Contracts\RouteService;
use App\Transit\Dto\GeoPoint;
use App\Transit\JourneyRanker;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

/**
 * The numbers the accuracy audit of 3 Nov 2026 measured by hand, every day, with a warning when one
 * goes bad: routes, live times, notices, Composer, Paperwork's cards and events. Reads only. A number
 * with too little behind it is shown, never judged.
 */
#[Signature('accuracy:daily')]
#[Description('Measure routes, live times, notices, Composer, Paperwork and events; warn when one goes bad')]
class AccuracyDaily extends Command
{
    /** Answers a day needs before Composer's rates mean anything. */
    private const COMPOSER_MIN_ANSWERS = 20;

    /** Fixed trips: a short hop, across town, and out to the edge. */
    private const TRIPS = [
        'Rudolfplatz to Hohenzollernring' => [50.9367, 6.9401, 50.9405, 6.9396],
        'Ehrenfeld to Neumarkt' => [50.9495, 6.9180, 50.9365, 6.9476],
        'Weiden to Kalk' => [50.9380, 6.8320, 50.9410, 7.0000],
        'Rodenkirchen to Porz' => [50.8910, 6.9910, 50.8855, 7.0590],
    ];

    /** @var list<array{name: string, value: string, bad: bool}> */
    private array $rows = [];

    public function handle(): int
    {
        foreach (['routes', 'liveTimes', 'notices', 'composer', 'paperwork', 'events'] as $part) {
            try {
                $this->{$part}();
            } catch (Throwable $error) {
                $this->row($part, 'could not be measured: '.$error->getMessage(), true);
            }
        }

        $this->table(['Number', 'Value', ''], collect($this->rows)->map(fn (array $row): array => [$row['name'], $row['value'], $row['bad'] ? '<fg=red>BAD</>' : 'ok'])->all());
        RedisLogger::log('accuracy_daily', $this->rows);

        $bad = collect($this->rows)->where('bad', true);
        if ($bad->isNotEmpty()) {
            $names = $bad->map(fn (array $row): string => "{$row['name']}: {$row['value']}")->implode('; ');
            Log::warning('Accuracy check: numbers gone bad', ['numbers' => $names]);
            // Through Sentry, so someone hears of it.
            report(new RuntimeException('Accuracy check: '.$names));
        }

        return self::SUCCESS;
    }

    private function row(string $name, string $value, bool $bad = false): void
    {
        $this->rows[] = ['name' => $name, 'value' => $value, 'bad' => $bad];
    }

    /** Each fixed trip finds a way, none leads with a bike, and the list offers a real choice. */
    private function routes(): void
    {
        $routes = app(RouteService::class);
        $ranker = app(JourneyRanker::class);
        $found = $choices = $bike = 0;
        $tomorrow = CarbonImmutable::now('Europe/Berlin')->addDay()->setTime(10, 0);
        foreach (self::TRIPS as [$fromLat, $fromLng, $toLat, $toLng]) {
            $result = $routes->withTimeBudget(6.0)->plan(new GeoPoint($fromLat, $fromLng), new GeoPoint($toLat, $toLng), $tomorrow, 10);
            $ways = $ranker->rank($result->journeys);
            $found += $ways !== [] && $result->source !== 'degraded' ? 1 : 0;
            $bike += ($ways[0] ?? null)?->mode() === 'bike' ? 1 : 0;
            $choices += count(array_unique(array_map(fn ($way): string => $way->mode().implode('>', $way->lines()), array_slice($ways, 0, 3)))) >= 2 ? 1 : 0;
        }
        $trips = count(self::TRIPS);
        $this->row('Fixed trips with a route', "{$found} of {$trips}", $found < $trips);
        $this->row('Fixed trips led by a bike', (string) $bike, $bike > 0);
        $this->row('Fixed trips with a real choice', "{$choices} of {$trips}", $choices * 2 < $trips);
    }

    /** From api:health's own log, the last day of it. */
    private function liveTimes(): void
    {
        $runs = Redis::connection('default')->zrangebyscore('api_health_check', now()->subDay()->getTimestamp(), '+inf');
        $up = [];
        foreach ($runs as $run) {
            foreach ((array) json_decode((string) $run, true) as $check) {
                if (is_array($check) && isset($check['name'], $check['status'])) {
                    $up[$check['name']][] = $check['status'] === 'up';
                }
            }
        }
        foreach (['VRS TRIAS' => 95, 'VRS GTFS-RT' => 95, 'MOTIS (Routing)' => 95] as $name => $least) {
            if (isset($up[$name])) {
                $share = (int) round(100 * count(array_filter($up[$name])) / count($up[$name]));
                $this->row("{$name} up, 24 h", "{$share}%", $share < $least);
            }
        }
    }

    /** Open notices with their English brief. */
    private function notices(): void
    {
        $open = app(DisruptionService::class)->openNotices()->unique(fn ($notice): string => NoticeBrief::hashFor((string) $notice->title, $notice->summary));
        if ($open->isEmpty()) {
            return;
        }
        $hashes = $open->map(fn ($notice): string => NoticeBrief::hashFor((string) $notice->title, $notice->summary))->values();
        $briefed = NoticeBrief::query()->where('locale', 'en')->whereIn('input_hash', $hashes)->distinct()->count('input_hash');
        $share = (int) round(100 * $briefed / $open->count());
        $this->row('Open notices in English', "{$share}% of {$open->count()}", $share < 80);
    }

    /** The last day's answers. */
    private function composer(): void
    {
        $traces = ComposerTrace::query()->where('created_at', '>=', now()->subDay())->get(['outcome', 'kept', 'dropped', 'timings']);
        $answers = $traces->count();
        $judged = $answers >= self::COMPOSER_MIN_ANSWERS;
        if ($answers === 0) {
            $this->row('Composer answers, 24 h', '0');

            return;
        }
        $fallback = $traces->where('outcome', '!=', 'ai')->count();
        $kept = $traces->sum(fn (ComposerTrace $t): int => count($t->kept ?? []));
        $dropped = $traces->sum(fn (ComposerTrace $t): int => count($t->dropped ?? []));
        $droppedShare = $kept + $dropped > 0 ? (int) round(100 * $dropped / ($kept + $dropped)) : 0;
        $fallbackShare = (int) round(100 * $fallback / $answers);
        $ms = $traces->map(fn (ComposerTrace $t): int => collect($t->timings ?? [])->sum(fn (mixed $step): int => is_array($step) ? (int) ($step[1] ?? 0) : 0))->sort()->values();
        $p95 = $ms[(int) floor($ms->count() * 0.95)] ?? 0;
        $this->row('Composer answers, 24 h', (string) $answers);
        $this->row('Composer answers not from the AI', "{$fallbackShare}%", $judged && $fallbackShare > 5);
        $this->row('Composer sentences dropped', "{$droppedShare}%", $judged && $droppedShare > 15);
        $this->row('Composer answer time, p95', round($p95 / 1000, 1).' s', $judged && $p95 > 10000);
    }

    /** Cards drop out of plans when their review lapses, all together: warned of before it. */
    private function paperwork(): void
    {
        $published = Task::query()->where('is_published', true)->whereNotNull('review_due_at');
        $soonest = (clone $published)->min('review_due_at');
        if ($soonest === null) {
            $this->row('Paperwork cards reviewed', 'none', true);

            return;
        }
        $days = (int) now()->startOfDay()->diffInDays(CarbonImmutable::parse($soonest), false);
        $this->row('Paperwork: days until a card\'s review lapses', (string) $days, $days < 7);
    }

    /** Listings still to come today and tomorrow. */
    private function events(): void
    {
        $today = CarbonImmutable::now('Europe/Berlin');
        $ahead = Event::occurringBetween($today, $today->endOfDay())->count() + Event::occurringBetween($today->addDay()->startOfDay(), $today->addDay()->endOfDay())->count();
        $this->row('Listings today and tomorrow', (string) $ahead, $ahead < 20);
    }
}
