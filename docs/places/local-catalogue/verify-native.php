<?php

require __DIR__.'/bootstrap-local.php';
use App\Composer\CandidateRepository;
use App\Composer\Constraints;
use App\Http\Resources\PlaceResource;
use App\Media\MediaSourcePolicy;
use App\Media\PublishedMediaSelector;
use App\Models\Spot;
use App\Places\DestinationGrouping;
use App\Places\PlaceFacts;
use App\Places\PlaceIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

$user = localUser();
$day = CarbonImmutable::parse('2026-10-01 10:00:00', 'Europe/Berlin');
DB::beginTransaction();
try {
    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
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
    $held = Spot::whereNull('source')->whereNull('canonical_spot_id')->orderBy('id')->pluck('id')->all();
    foreach (array_chunk($held, 200) as $chunk) {
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
            $candidates = $repository->candidatesFor($constraints, $lat, $lon);
            foreach ($candidates as $candidate) {
                localCheck(str_starts_with($candidate->id, 'spot:') && in_array('soccer', $candidate->placeFacts['activities'], true)
                    && $candidate->placeFacts['access']['value'] === 'public'
                    && ($budget !== 'free' || $candidate->costTier === 'free'), 'Football query admitted unsupported place.');
            }
            $football[$origin][$budget ?? 'any_budget'] = count($candidates);
        }
    }
    localCheck(DB::table('users')->count() === 0, 'Synthetic user persisted.');
    $summary = ['status' => 'passed', 'checked_at' => gmdate('c'), 'snapshot_sha256' => hash_file('sha256', getenv('PLACES_LOCAL_PRIVATE').'/catalogue.json'),
        'scope' => 'local_native_places_and_composer_place_candidates', 'stored_records' => Spot::count(), 'eligible_place_components' => count($ids),
        'general_destinations' => count($general), 'shared_contract_records_checked' => $checked, 'fact_counts' => $factsCounts,
        'retained_aliases_verified' => $aliasChecks, 'alias_api_samples' => $aliasApiChecks, 'held_source_null_exclusions' => count($held),
        'held_detail_api_samples' => min(12, count($held)), 'api_totals' => $api, 'football_within_3km' => $football,
        'general_destinations_with_policy_publishable_hero' => $photos, 'policy_photo_coverage_percent' => round($photos / count($general) * 100, 3),
        'photo_urls_freshly_revalidated' => false, 'full_composer_conversation_verified' => false, 'real_users_imported' => 0, 'remote_changed' => false];
} finally {
    DB::rollBack();
}
localVerify(localPdo(), localSnapshot());
localSummary('native-verification.json', $summary);
