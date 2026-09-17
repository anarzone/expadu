<?php

namespace App\Console\Commands;

use App\Models\Spot;
use App\Places\PlaceFacts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

#[Signature('places:audit-facts {--output= : Optional JSON output path relative to the application root}')]
#[Description('Report source, location and practical-fact coverage without changing place data')]
class AuditPlaceFacts extends Command
{
    private const GENERIC_NAME = '/^(Spielplatz|Bolzplatz|Basketballplatz|Tennisplatz|Tischtennisplatte|Boulebahn|Skatepark|Hundewiese|Grillplatz|Picknickplatz)(\s*·.*)?$/iu';

    private const RESTRICTED_ACCESS = ['private', 'no', 'customers', 'members', 'permit'];

    public function handle(): int
    {
        $spots = Spot::query()
            ->canonical()
            ->where('is_active', true)
            ->where('is_recommendable', true)
            ->orderBy('id')
            ->get(['id', 'name', 'lat', 'lng', 'source', 'source_id', 'tags', 'opening_hours', 'website', 'phone', 'description']);

        $ids = [
            'generic_names' => [],
            'missing_coordinates' => [],
            'missing_provenance' => [],
            'restricted_access' => [],
            'fee_evidence' => [],
            'hours_evidence' => [],
            'with_website' => [],
            'with_phone' => [],
            'with_description' => [],
        ];

        $factsBySpot = app(PlaceFacts::class)->resolveMany($spots);
        foreach ($spots as $spot) {
            $facts = $factsBySpot[$spot->id];
            $name = (string) ($facts['name']['value'] ?? '');
            $mapPoint = $facts['location']['map_point'];
            $access = $facts['access'];
            $provenanceUrls = [
                $facts['name']['source_url'],
                $mapPoint['source_url'],
                $access['source_url'],
                $facts['fee']['source_url'],
                $facts['hours']['source_url'],
                $facts['contact']['website']['source_url'],
                $facts['description']['source_url'],
            ];
            $hasProvenance = filled($spot->source) && filled($spot->source_id)
                || collect($provenanceUrls)->contains(fn (mixed $url): bool => filled($url));

            $this->collect($ids, 'generic_names', (bool) preg_match(self::GENERIC_NAME, $name), $spot->id);
            $this->collect($ids, 'missing_coordinates', $mapPoint['lat'] === null || $mapPoint['lng'] === null, $spot->id);
            $this->collect($ids, 'missing_provenance', ! $hasProvenance, $spot->id);
            $this->collect($ids, 'restricted_access', in_array($access['value'], self::RESTRICTED_ACCESS, true) || filled($access['conditional']) || $access['status'] === 'conflicting', $spot->id);
            $this->collect($ids, 'fee_evidence', filled($facts['fee']['raw']) || $facts['fee']['status'] === 'known', $spot->id);
            $this->collect($ids, 'hours_evidence', filled($facts['hours']['raw']) || filled($facts['hours']['parsed']), $spot->id);
            $this->collect($ids, 'with_website', filled($facts['contact']['website']['value']), $spot->id);
            $this->collect($ids, 'with_phone', filled($facts['contact']['phone']['value']), $spot->id);
            $this->collect($ids, 'with_description', filled($facts['description']['value']), $spot->id);
        }

        $report = [
            'environment' => app()->environment(),
            'commit' => config('app.commit'),
            'generated_at' => now()->toIso8601String(),
            'denominator' => $spots->count(),
            'counts' => array_map('count', $ids),
            'place_ids' => $ids,
        ];
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (filled($this->option('output'))) {
            $output = str_replace('\\', '/', trim((string) $this->option('output')));
            if (str_starts_with($output, '/') || collect(explode('/', $output))->contains('..')) {
                $this->error('Audit output must be a path inside the application root.');

                return self::FAILURE;
            }
            $path = base_path($output);
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, $json."\n");
        }

        $this->output->writeln($json, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    /** @param array<string, list<int>> $ids */
    private function collect(array &$ids, string $metric, bool $matches, int $spotId): void
    {
        if ($matches) {
            $ids[$metric][] = $spotId;
        }
    }
}
