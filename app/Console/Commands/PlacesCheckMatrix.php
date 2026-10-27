<?php

namespace App\Console\Commands;

use App\Composer\Answers\EventAnswers;
use App\Composer\Assistant\AssistantTools;
use App\Composer\CandidateRepository;
use App\Enums\LocationSource;
use App\Models\User;
use App\Places\OpeningAnswer;
use App\Services\LocationContext;
use App\Services\WeatherService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The places quality gate: each ask in docs/places/quality/test-matrix.json runs
 * through Composer's search_places tool (no AI) and is checked for kind, area,
 * distinct names, honest opening hours and enough results. Read-only.
 */
#[Signature('places:check-matrix {--ask= : run only asks containing this text} {--show : print each tool answer}')]
#[Description('Run the places test matrix against Composer\'s place search (read-only)')]
class PlacesCheckMatrix extends Command
{
    public function handle(CandidateRepository $catalogue): int
    {
        $matrix = json_decode((string) file_get_contents(base_path('docs/places/quality/test-matrix.json')), true, flags: JSON_THROW_ON_ERROR);
        $user = User::query()->where('email', 'checks@expadu.test')->first() ?? User::query()->firstOrFail();
        $user->needs_step_free = false; // in memory only: the matrix asks for features explicitly
        $failed = 0;

        foreach ($matrix['asks'] as $case) {
            if ($this->option('ask') !== null && ! str_contains(mb_strtolower($case['ask']), mb_strtolower((string) $this->option('ask')))) {
                continue;
            }
            $case += $matrix['defaults'];
            $at = CarbonImmutable::parse($case['at'], 'Europe/Berlin');
            [$lat, $lng] = $case['from'];
            $tools = new AssistantTools($user, $at, new LocationContext($lat, $lng, LocationSource::Confirmed, 'matrix'),
                $catalogue, app(EventAnswers::class), app(WeatherService::class));

            $answer = $tools->run('search_places', $case['input']);
            $rows = $this->rows($answer);
            $candidates = collect($catalogue->byIds(array_column($rows, 'id'), $at))->keyBy('id');
            $problems = [];

            if (count($rows) < $case['min_results']) {
                $problems[] = count($rows)." result(s), wanted ≥{$case['min_results']}";
            }
            if ($rows === [] && isset($case['if_empty_says']) && ! str_contains(mb_strtolower($answer), mb_strtolower($case['if_empty_says']))) {
                $problems[] = "empty answer doesn't say \"{$case['if_empty_says']}\"";
            }
            foreach ($rows as $row) {
                $place = $candidates->get($row['id']);
                if (! in_array($row['kind'], $case['kinds'], true)) {
                    $problems[] = "wrong kind: {$row['name']} ({$row['kind']})";
                }
                if (isset($case['areas']) && ! in_array($row['veedel'], $case['areas'], true)) {
                    $problems[] = "outside the area: {$row['name']} ({$row['veedel']})";
                }
                // Owner, 23 Oct: never a German kind label as a name, in any answer.
                if (preg_match('/^(Spielplatz|Bolzplatz|Basketballplatz|Tennisplatz|Tischtennisplatte|Tischtennis|Boulebahn|Hundewiese|Hundefreilauffläche|Grillplatz|Picknickplatz|Sportplatz|Fußballplatz|Spielwiese|Kinderspielplatz)\b/iu', $row['name']) === 1) {
                    $problems[] = "German kind label as a name: {$row['name']}";
                }
                if (($case['open_from_listed_hours'] ?? false) && $place !== null && OpeningAnswer::isOpenAt($place, $at) !== true) {
                    $problems[] = "not open by listed hours: {$row['name']}";
                }
                // Coordinator, 24 Oct: a "free" ask never offers a place that isn't public (school grounds, private).
                if (($case['public_only'] ?? false) && $place !== null && ($place->placeFacts['public_use']['value'] ?? null) === 'no') {
                    $problems[] = 'not public ('.($place->placeFacts['public_use']['basis'] ?? '?')."): {$row['name']}";
                }
                if (isset($case['requires']) && $place !== null && ! $this->meets($place->placeFacts['practical'] ?? [], $case['requires'])) {
                    $problems[] = "{$case['requires']} not listed: {$row['name']}";
                }
            }
            // Chains repeat names (two Merzenich bakeries are two places); a park or a
            // facility shown twice is one place twice (owner: one park, one answer).
            $once = ['Pitch', 'Park', 'Playground', 'Table tennis', 'Tennis court', 'Basketball court', 'Dog park', 'Boules', 'Sports centre', 'Skatepark', 'Picnic spot', 'Lake', 'Woods & nature'];
            $names = array_column(array_filter($rows, fn (array $row): bool => in_array($row['kind'], $once, true)), 'name');
            if (count($names) !== count(array_unique($names))) {
                $problems[] = 'the same place twice: '.implode(', ', array_unique(array_diff_assoc($names, array_unique($names))));
            }

            $problems === [] ? $this->line("<info>PASS</info> {$case['ask']} (".count($rows).')') : $failed++;
            if ($problems !== []) {
                $this->line("<error>FAIL</error> {$case['ask']} (".count($rows).')');
                foreach (array_slice(array_unique($problems), 0, 6) as $problem) {
                    $this->line("     · {$problem}");
                }
            }
            if ($this->option('show')) {
                $this->line(preg_replace('/^/m', '       ', $answer));
            }
        }

        $this->newLine();
        $this->line($failed === 0 ? '<info>All asks pass.</info>' : "<error>{$failed} ask(s) fail.</error>");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<array{id: string, name: string, kind: string, veedel: ?string}> the places in a search_places answer */
    private function rows(string $answer): array
    {
        preg_match_all('/^\[(spot:\d+)\] "(.+?)" \(([^,)]+)[^)]*\)(?: in ([^.\n]+))?\./mu', $answer, $matches, PREG_SET_ORDER);

        return array_map(fn (array $m): array => ['id' => $m[1], 'name' => $m[2], 'kind' => $m[3], 'veedel' => $m[4] ?? null], $matches);
    }

    /** @param array<string, array{value: mixed, status: string}> $practical */
    private function meets(array $practical, string $need): bool
    {
        $value = fn (string $key): ?string => ($practical[$key]['status'] ?? null) === 'known' ? mb_strtolower((string) $practical[$key]['value']) : null;

        return match ($need) {
            'wifi' => in_array($value('internet_access'), ['wlan', 'yes', 'wifi'], true),
            'vegan' => in_array($value('diet:vegan'), ['yes', 'only'], true),
            'wheelchair' => in_array($value('wheelchair'), ['yes', 'designated', 'limited'], true),
            default => false,
        };
    }
}
