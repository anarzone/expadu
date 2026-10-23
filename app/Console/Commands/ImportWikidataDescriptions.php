<?php

namespace App\Console\Commands;

use App\Media\CommonsPhotoResolver;
use App\Models\Spot;
use App\Places\PlaceFacts;
use App\Places\RecordPlaceObservation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Short descriptions for places OSM links to Wikidata ("art museum in Cologne,
 * Germany"), English first, then German. Wikidata is CC0. Recorded as
 * provider "wikidata" only where the place lists no description of its own.
 */
#[Signature('places:import-wikidata-descriptions {--dry-run}')]
#[Description('Add Wikidata descriptions to linked places that have none')]
class ImportWikidataDescriptions extends Command
{
    public function handle(RecordPlaceObservation $record, PlaceFacts $facts): int
    {
        $spots = Spot::query()->where('is_active', true)->whereNotNull('tags')->get()
            ->filter(fn (Spot $spot): bool => preg_match('/^Q\d+$/', (string) ($spot->tags['wikidata'] ?? '')) === 1)
            ->filter(fn (Spot $spot): bool => ($facts->resolve($spot)['description']['status'] ?? 'unknown') !== 'known');
        $observedAt = CarbonImmutable::now()->startOfSecond();
        $stats = ['linked_without_description' => $spots->count(), 'recorded' => 0, 'no_description' => 0, 'failed' => 0];

        foreach ($spots->chunk(50) as $chunk) {
            $ids = $chunk->map(fn (Spot $spot): string => $spot->tags['wikidata'])->unique()->implode('|');
            $entities = Http::withUserAgent(CommonsPhotoResolver::USER_AGENT)->timeout(30)
                ->get('https://www.wikidata.org/w/api.php', ['action' => 'wbgetentities', 'ids' => $ids, 'props' => 'descriptions', 'languages' => 'en|de', 'format' => 'json'])
                ->json('entities', []);
            foreach ($chunk as $spot) {
                $qid = $spot->tags['wikidata'];
                $text = trim((string) ($entities[$qid]['descriptions']['en']['value'] ?? $entities[$qid]['descriptions']['de']['value'] ?? ''));
                // Bare category words ("park", "Wikimedia disambiguation page") say nothing.
                // Nor does "Park in Cologne, Germany": only the kind and the place, already shown.
                $restates = preg_match('/^[\p{L}-]+(?:\s[\p{L}-]+)?\s(?:in|im|bei)\s[^,]+(?:,\s*(?:Germany|Deutschland|North Rhine-Westphalia|Cologne))*$/iu', $text) === 1;
                if (mb_strlen($text) < 12 || $restates || str_contains(mb_strtolower($text), 'wikimedia')) {
                    $stats['no_description']++;

                    continue;
                }
                $text = mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
                if ($this->option('dry-run')) {
                    $stats['recorded']++;

                    continue;
                }
                try {
                    $record->record($spot, [
                        'provider' => 'wikidata',
                        'provider_record_id' => $qid,
                        'source_url' => 'https://www.wikidata.org/wiki/'.$qid,
                        'observed_at' => $observedAt->toIso8601String(),
                        'ingestion_key' => 'wikidata:'.$qid.':'.$observedAt->format('Ymd'),
                        'payload' => ['description' => mb_substr($text, 0, 1000)],
                    ]);
                    $stats['recorded']++;
                } catch (Throwable $e) {
                    $stats['failed']++;
                    $this->warn("  spot {$spot->id}: {$e->getMessage()}");
                }
            }
            usleep(500_000);
        }

        $this->table(array_keys($stats), [array_values($stats)]);

        return $stats['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
