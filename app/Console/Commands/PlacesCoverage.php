<?php

namespace App\Console\Commands;

use App\Enums\SpotCategory;
use App\Media\PublishedMediaSelector;
use App\Models\Spot;
use App\Places\PlaceFacts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * How complete the places people can be offered are, per kind: the share with
 * listed hours, website, phone, Wi-Fi, a street, a rights-cleared photo and
 * the other facts cards and Composer use. Read-only; writes a Markdown report
 * (and JSON beside it) so each enrichment step can show before and after.
 */
#[Signature('places:coverage {--out= : report path, default docs/places/coverage/<date>.md} {--label= : what this run measures, e.g. "after Overture"}')]
#[Description('Report per-kind completeness of suggestible places (read-only)')]
class PlacesCoverage extends Command
{
    /** Columns: fact => how a place has it. */
    private const FACTS = ['hours', 'website', 'phone', 'wifi', 'sockets', 'laptops', 'street', 'photo', 'outdoor_seating', 'diet', 'wheelchair', 'cuisine', 'description'];

    public function handle(PlaceFacts $facts, PublishedMediaSelector $media): int
    {
        $counts = [];
        Spot::query()->where('is_active', true)->where('is_recommendable', true)
            ->with(['mediaAttachments.mediaAsset', 'identityAliases.mediaAttachments.mediaAsset'])
            ->chunkById(300, function (Collection $spots) use ($facts, $media, &$counts): void {
                $resolved = $facts->resolveMany($spots);
                foreach ($spots as $spot) {
                    $kind = $spot->category instanceof SpotCategory ? $spot->category->value : (string) $spot->category;
                    $fact = $resolved->get($spot->id) ?? [];
                    $counts[$kind] ??= ['places' => 0, 'sources' => []] + array_fill_keys(self::FACTS, 0);
                    $counts[$kind]['places']++;
                    $counts[$kind]['sources'][$spot->source ?? 'none'] = ($counts[$kind]['sources'][$spot->source ?? 'none'] ?? 0) + 1;
                    foreach ($this->has($fact, $spot, $media) as $key => $present) {
                        $counts[$kind][$key] += $present ? 1 : 0;
                    }
                }
            });

        ksort($counts);
        $total = ['places' => array_sum(array_column($counts, 'places'))] + array_combine(self::FACTS, array_map(fn (string $key): int => array_sum(array_column($counts, $key)), self::FACTS));
        $path = $this->option('out') ?: base_path('docs/places/coverage/'.now('Europe/Berlin')->format('Y-m-d').'.md');
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $this->markdown($counts, $total));
        file_put_contents(preg_replace('/\.md$/', '.json', $path), json_encode(['generated_at' => now()->toIso8601String(), 'label' => $this->option('label'), 'total' => $total, 'kinds' => $counts], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $this->line("{$total['places']} suggestible places in ".count($counts).' kinds → '.$path);
        foreach (['hours', 'website', 'phone', 'wifi', 'street', 'photo'] as $key) {
            $this->line(sprintf('  %-8s %3d%%', $key, $this->pct($total[$key], $total['places'])));
        }

        return self::SUCCESS;
    }

    /** @return array<string, bool> */
    private function has(array $fact, Spot $spot, PublishedMediaSelector $media): array
    {
        $known = fn (?array $f): bool => ($f['status'] ?? null) === 'known' && ($f['value'] ?? null) !== null && $f['value'] !== '';
        $practical = $fact['practical'] ?? [];
        $address = $known($fact['contact']['address'] ?? null) ? trim(explode(',', (string) $fact['contact']['address']['value'])[0]) : '';

        return [
            'hours' => ($fact['hours']['status'] ?? null) === 'known',
            'website' => $known($fact['contact']['website'] ?? null),
            'phone' => $known($fact['contact']['phone'] ?? null),
            'wifi' => $known($practical['internet_access'] ?? null),
            'sockets' => $known($practical['power_supply'] ?? null),
            'laptops' => $known($practical['laptops'] ?? null),
            'street' => $address !== '' && preg_match('/^\d{5}\b/u', $address) !== 1,
            'photo' => $media->select($spot, 'hero') !== null,
            'outdoor_seating' => $known($practical['outdoor_seating'] ?? null),
            'diet' => $known($practical['diet:vegetarian'] ?? null) || $known($practical['diet:vegan'] ?? null),
            'wheelchair' => $known($practical['wheelchair'] ?? null),
            'cuisine' => $known($practical['cuisine'] ?? null),
            'description' => $known($fact['description'] ?? null),
        ];
    }

    private function pct(int $part, int $whole): int
    {
        return $whole === 0 ? 0 : (int) round(100 * $part / $whole);
    }

    /**
     * @param  array<string, array<string, mixed>>  $counts
     * @param  array<string, int>  $total
     */
    private function markdown(array $counts, array $total): string
    {
        $label = $this->option('label') ? ' — '.$this->option('label') : '';
        $head = '| Kind | Places | '.implode(' | ', array_map(fn (string $k): string => ucfirst(str_replace('_', ' ', $k)), self::FACTS)).' | Sources |';
        $rule = '|'.str_repeat(' --- |', count(self::FACTS) + 3);
        $row = fn (string $name, array $c, string $sources): string => "| {$name} | {$c['places']} | ".implode(' | ', array_map(fn (string $k): string => $this->pct($c[$k], $c['places']).'%', self::FACTS))." | {$sources} |";

        $lines = [
            '# Places coverage'.$label,
            '',
            'Generated '.now('Europe/Berlin')->format('j M Y, H:i').' by `php artisan places:coverage` on the local database. Suggestible places only (active, recommendable). A fact counts when it is listed (known), never when assumed; photo means a rights-cleared hero photo the media gate would serve.',
            '',
            $head,
            $rule,
            $row('**All**', $total, ''),
        ];
        uasort($counts, fn (array $a, array $b): int => $b['places'] <=> $a['places']);
        foreach ($counts as $kind => $c) {
            $label = SpotCategory::tryFrom($kind)?->label() ?? $kind;
            $sources = collect($c['sources'])->sortDesc()->map(fn (int $n, string $s): string => "{$s} {$n}")->implode(', ');
            $lines[] = $row($label, $c, $sources);
        }

        return implode("\n", $lines)."\n";
    }
}
