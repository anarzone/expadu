<?php

require __DIR__.'/local-native-bootstrap.php';
use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Composer\FeasibilityFilter;
use App\Http\Resources\PlaceResource;
use App\Media\MediaSourcePolicy;
use App\Media\PublishedMediaSelector;
use App\Models\Spot;
use App\Places\DestinationGrouping;
use App\Places\PlaceFacts;
use App\Places\PlaceIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

$user = localUser();
localCheck(! CarbonImmutable::hasTestNow(), 'Verification must use the actual clock.');
$day = CarbonImmutable::now('Europe/Berlin');
localCheck(hash_equals(HistoricalCatalogueSnapshot::SHA256, hash_file('sha256', storage_path('app/private/places-local/2026-10-01/catalogue.json'))), 'Historical snapshot checksum differs.');
$historicalAsOf = '2026-10-01T09:18:34+00:00'; // Bound to the verified, immutable snapshot checksum above.
$startedAt = gmdate('c');
DB::beginTransaction();
try {
    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
    $readOnly = DB::selectOne('SHOW transaction_read_only')->transaction_read_only;
    localCheck($readOnly === 'on', 'Native verification must remain read only.');
    localCheck(Spot::count() === 11802 && Spot::min('id') > 1000000, 'Expected historical import and place ID offset are missing.');
    localCheck(DB::table('veedels')->count() === 86, 'Source boundaries are missing.');
    $grouping = app(DestinationGrouping::class);
    $ids = $grouping->eligible(Spot::query(), true)->orderBy('spots.id')->pluck('spots.id')->all();
    $general = array_fill_keys($grouping->general(Spot::query())->pluck('spots.id')->all(), true);
    $repository = app(CandidateRepository::class);
    $request = Request::create('/api/places', 'GET');
    $request->setUserResolver(static fn () => $user);
    $checked = $photos = 0;
    $factsCounts = [];
    foreach (array_chunk($ids, 200) as $chunk) {
        $spots = Spot::whereIn('id', $chunk)->with(['mediaAttachments.mediaAsset', 'identityAliases.mediaAttachments.mediaAsset'])->orderBy('id')->get();
        app(PlaceFacts::class)->attach($spots);
        $candidates = $repository->byIds(array_map(static fn (int $id): string => 'spot:'.$id, $chunk), $day);
        $byId = array_column($candidates, null, 'id');
        foreach ($spots as $spot) {
            $spot->recommendation_available = true;
            $body = (new PlaceResource($spot))->resolve($request);
            $facts = $spot->getRelation(PlaceFacts::SNAPSHOT_RELATION);
            $candidate = $byId['spot:'.$spot->id] ?? null;
            localCheck($spot->source !== null && $candidate !== null && is_string($body['name']) && trim($body['name']) !== ''
                && ! preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $body['name'])
                && $candidate->name === $body['name'] && is_numeric($body['lat']) && abs($body['lat']) <= 90
                && is_numeric($body['lng']) && abs($body['lng']) <= 180
                && $candidate->lat === $body['routing_lat'] && $candidate->lng === $body['routing_lng']
                && $candidate->placeFacts === $facts
                && $body['place_facts'] === array_intersect_key($facts, $body['place_facts'])
                && ($facts['fee']['value'] !== 'unknown' || $candidate->costTier === 'unknown'), 'Native Places/Composer contract mismatch: '.$spot->id);
            foreach (['source' => $spot->source, 'name_kind' => $facts['name_kind'], 'access' => $facts['access']['value'], 'fee' => $facts['fee']['value']] as $key => $value) {
                $factsCounts[$key][$value] = ($factsCounts[$key][$value] ?? 0) + 1;
            }
            if ($body['photo_url'] !== null) {
                $asset = app(PublishedMediaSelector::class)->select($spot, 'hero');
                localCheck($asset !== null && $asset->rights_status === 'approved' && $asset->health_status === 'active'
                    && ! app(MediaSourcePolicy::class)->excludesAsset($asset), 'Media policy failed.');
                if (isset($general[$spot->id])) {
                    $photos++;
                }
            }
            $checked++;
        }
        echo json_encode(['native_contracts_checked' => $checked, 'total' => count($ids)]).PHP_EOL;
    }
    $aliases = Spot::whereNotNull('canonical_spot_id')->orderBy('id')->get(['id', 'canonical_spot_id']);
    $aliasChecks = $aliasApiChecks = 0;
    foreach ($aliases as $alias) {
        $resolved = app(PlaceIdentity::class)->candidateIds(['spot:'.$alias->id]);
        localCheck($resolved === ['spot:'.$alias->canonical_spot_id], 'Retained alias changed canonical target.');
        $aliasChecks++;
        if ($aliasApiChecks < 12 && in_array($alias->canonical_spot_id, $ids, true)) {
            $body = localApi('/api/places/'.$alias->id, $user)['data'];
            localCheck($body['id'] === $alias->canonical_spot_id, 'Alias detail target mismatch.');
            $aliasApiChecks++;
        }
    }
    localCheck($aliasChecks === 798, 'Retained alias count differs from the historical snapshot.');
    $held = Spot::whereNull('source')->whereNull('canonical_spot_id')->orderBy('id')->pluck('id')->all();
    $allHeld = Spot::query()->canonical()->whereNotIn('id', $ids)->orderBy('id')->pluck('id')->all();
    foreach (array_chunk($allHeld, 200) as $chunk) {
        localCheck($repository->byIds(array_map(static fn (int $id): string => 'spot:'.$id, $chunk), $day) === [], 'Held place entered Composer.');
    }
    foreach (array_slice($held, 0, 12) as $id) {
        $body = localApi('/api/places/'.$id, $user)['data'];
        localCheck($body['id'] === $id && $body['recommendation_status'] === 'unavailable'
            && $body['open_now'] === null && $body['price_text'] === null, 'Held detail must retain identity and suppress unsupported live facts.');
    }
    $api = [];
    foreach (['all' => null, 'food_drink' => 'food_drink', 'culture' => 'culture', 'park' => 'park', 'pitch' => 'pitch', 'court' => 'court', 'playground' => 'playground'] as $key => $category) {
        $body = localApi('/api/places'.($category ? '?category='.$category : ''), $user);
        foreach ($body['data'] as $item) {
            localCheck(isset($general[$item['id']]), 'List returned held record.');
        }
        $api[$key] = $body['meta']['total'];
    }
    $football = [];
    foreach (['central' => [50.9384, 6.9600], 'north' => [51.0470, 6.8830], 'east' => [50.9600, 7.0690]] as $origin => [$lat,$lon]) {
        foreach ([null, 'free'] as $budget) {
            $constraints = new Constraints($day, $day->addHours(10), budget: $budget, activities: ['soccer'], radiusKm: 3);
            $candidates = app(FeasibilityFilter::class)->filter($constraints, $repository->candidatesFor($constraints, $lat, $lon));
            foreach ($candidates as $candidate) {
                localCheck(str_starts_with($candidate->id, 'spot:') && in_array('soccer', $candidate->placeFacts['activities'], true)
                    && $candidate->placeFacts['access']['value'] === 'public'
                    && ($budget !== 'free' || $candidate->costTier === 'free'), 'Football query admitted unsupported place.');
            }
            $football[$origin][$budget ?? 'any_budget'] = count($candidates);
        }
    }
    localCheck(! $user->exists && DB::selectOne('SHOW transaction_read_only')->transaction_read_only === 'on', 'Read-only or unsaved-user guard changed.');
    Http::assertNothingSent();
    Queue::assertNothingPushed();
    Bus::assertNothingDispatched();
    Mail::assertNothingSent();
    Notification::assertNothingSent();
    $sourceHashes = [];
    foreach (['app/Composer/CandidateRepository.php', 'app/Composer/FeasibilityFilter.php', 'app/Places/PlaceFacts.php', 'app/Places/PlaceIdentity.php', 'app/Places/DestinationGrouping.php', 'app/Models/Spot.php', 'app/Http/Resources/PlaceResource.php', 'app/Media/PublishedMediaSelector.php', 'app/Media/MediaSourcePolicy.php'] as $path) {
        $sourceHashes[$path] = hash_file('sha256', base_path($path));
    }
    $summary = ['status' => 'passed', 'checked_at' => gmdate('c'), 'started_at' => $startedAt, 'snapshot_sha256' => HistoricalCatalogueSnapshot::SHA256, 'historical_as_of' => $historicalAsOf, 'evaluation_clock' => $day->toIso8601String(), 'local_spot_id_offset' => 1000000, 'transaction_read_only' => true, 'cache_store' => 'array', 'source_boundaries' => 86,
        'scope' => 'local_native_places_and_composer_place_candidates', 'stored_records' => Spot::count(), 'eligible_place_components' => count($ids),
        'general_destinations' => count($general), 'shared_contract_records_checked' => $checked, 'fact_counts' => $factsCounts,
        'retained_aliases_verified' => $aliasChecks, 'alias_api_samples' => $aliasApiChecks, 'held_source_null_canonical_exclusions' => count($held), 'source_null_records_including_aliases' => Spot::whereNull('source')->count(), 'all_canonical_held_exclusions' => count($allHeld),
        'held_detail_api_samples' => min(12, count($held)), 'api_totals' => $api, 'football_within_3km' => $football,
        'general_destinations_with_policy_publishable_hero' => $photos, 'policy_photo_coverage_percent' => round($photos / count($general) * 100, 3),
        'media_assets_all_surfaces' => DB::table('media_assets')->count(), 'media_attachments_all_surfaces' => DB::table('media_attachments')->count(),
        'place_media_attachments' => DB::table('media_attachments')->where('mediable_type', Spot::class)->count(),
        'place_media_assets_attached' => DB::table('media_attachments')->where('mediable_type', Spot::class)->distinct()->count('media_asset_id'),
        'source_sha256' => $sourceHashes, 'php_version' => PHP_VERSION,
        'photo_urls_freshly_revalidated' => false, 'full_composer_conversation_verified' => false, 'real_user_rows_loaded' => 0, 'synthetic_user_persisted' => false, 'catalogue_mutated' => false, 'remote_changed' => false, 'new_source_qualification' => false];
} finally {
    DB::rollBack();
}
$summary['transaction_rolled_back'] = DB::transactionLevel() === 0;
localCheck($summary['transaction_rolled_back'], 'Read-only transaction remained open.');
file_put_contents(__DIR__.'/local-native-summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
