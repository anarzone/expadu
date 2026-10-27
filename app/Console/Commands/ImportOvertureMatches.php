<?php

namespace App\Console\Commands;

use App\Models\PlaceFactObservation;
use App\Models\Spot;
use App\Places\RecordPlaceObservation;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Records Overture Maps contact facts (website, phone) for places matched one
 * to one with an Overture place: same name, within 50 m, a compatible kind
 * (matching runs offline over the release; see docs/places/enrichment). They
 * are observations from provider "overture": OSM stays the place's own source
 * and decides where it lists a value; Overture fills gaps, and a different
 * website domain or number reads as conflicting.
 *
 * Overture places are CDLA-Permissive-2.0 (Foursquare records Apache-2.0,
 * AllThePlaces CC0); the licence and source datasets of each record are kept.
 */
#[Signature('places:import-overture {file : JSONL of matches} {--release=2026-10-21.0} {--dry-run}')]
#[Description('Record Overture website/phone observations for matched places')]
class ImportOvertureMatches extends Command
{
    /** Links that are not a place's own website. */
    private const NOT_OWN_SITE = '/(^|\.)(google\.[a-z.]+|goo\.gl|g\.page|facebook\.com|fb\.com|instagram\.com|tiktok\.com|twitter\.com|x\.com|linktr\.ee|tripadvisor\.[a-z.]+|yelp\.[a-z.]+|lieferando\.de|wolt\.com|ubereats\.com|opentable\.[a-z.]+|thefork\.[a-z.]+|eatbu\.com|business\.site|wa\.me|whatsapp\.com)$/i';

    public function handle(RecordPlaceObservation $record): int
    {
        $observedAt = CarbonImmutable::now()->startOfSecond();
        $release = (string) $this->option('release');
        $stats = ['matches' => 0, 'recorded' => 0, 'no_contact' => 0, 'missing_spot' => 0, 'websites' => 0, 'phones' => 0, 'skipped_links' => 0, 'failed' => 0];

        foreach (new \SplFileObject((string) $this->argument('file')) as $line) {
            if (trim((string) $line) === '') {
                continue;
            }
            $match = json_decode((string) $line, true, flags: JSON_THROW_ON_ERROR);
            $stats['matches']++;
            $spot = Spot::query()->find($match['spot']);
            if ($spot === null) {
                $stats['missing_spot']++;

                continue;
            }

            $website = collect($match['websites'] ?? [])->first(function (string $url) use (&$stats): bool {
                $host = (string) parse_url($url, PHP_URL_HOST);
                $own = preg_match('#^https?://#i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false
                    && $host !== '' && preg_match(self::NOT_OWN_SITE, $host) !== 1;
                $stats['skipped_links'] += $own ? 0 : 1;

                return $own;
            });
            $phone = collect($match['phones'] ?? [])->first(fn (string $phone): bool => preg_match('/\d{6,}/', (string) preg_replace('/\D/', '', $phone)) === 1);
            $contact = array_filter(['website' => $website, 'phone' => $phone]);
            // A place first imported from Overture: this record replaces its earlier one,
            // so carry over what that said (its address) rather than erase it.
            $earlier = $spot->source === 'overture' && $spot->source_id === (string) $match['oid']
                ? PlaceFactObservation::query()->where('spot_id', $spot->id)->where('provider', 'overture')
                    ->where('provider_record_id', $spot->source_id)->where('ingestion_key', 'not like', "overture:{$release}:%")
                    ->orderByDesc('observed_at')->orderByDesc('id')->first()
                : null;
            if ($earlier !== null) {
                $contact = array_filter([...(array) ($earlier->payload['contact'] ?? []), ...$contact], fn (mixed $value): bool => $value !== null && $value !== '');
            }
            if ($contact === []) {
                $stats['no_contact']++;

                continue;
            }

            if ($this->option('dry-run')) {
                $stats['recorded']++;
                $stats['websites'] += isset($contact['website']) ? 1 : 0;
                $stats['phones'] += isset($contact['phone']) ? 1 : 0;

                continue;
            }

            try {
                $record->record($spot, [
                    'provider' => 'overture',
                    'provider_record_id' => (string) $match['oid'],
                    'source_url' => 'https://explore.overturemaps.org/?mode=explore#id='.rawurlencode((string) $match['oid']),
                    'observed_at' => $observedAt->toIso8601String(),
                    'ingestion_key' => "overture:{$release}:{$match['oid']}".($earlier !== null ? ':kept' : ''),
                    'payload' => ['contact' => $contact],
                ]);
                $stats['recorded']++;
                $stats['websites'] += isset($contact['website']) ? 1 : 0;
                $stats['phones'] += isset($contact['phone']) ? 1 : 0;
            } catch (Throwable $e) {
                $stats['failed']++;
                $this->warn("  spot {$spot->id}: {$e->getMessage()}");
            }
        }

        $this->table(array_keys($stats), [array_values($stats)]);

        return $stats['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
