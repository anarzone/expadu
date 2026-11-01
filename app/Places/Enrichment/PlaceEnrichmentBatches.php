<?php

namespace App\Places\Enrichment;

use App\Models\Spot;
use App\Models\SpotEnrichmentBatch;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Place enrichment through the Message Batches API (half price): submit
 * requests, then collect results once a batch has ended and apply the same
 * checks as a direct call.
 *
 * custom_id is "spot-<id>-<first 16 hex of the input hash>": a result whose
 * place has changed since submission is dropped (the next run sends the new
 * input), so evidence is always checked against the input the model saw.
 */
class PlaceEnrichmentBatches
{
    public function __construct(private readonly PlaceEnricher $enricher) {}

    /**
     * @param  list<PlaceEnrichmentInput>  $inputs
     * @return array{batches: list<SpotEnrichmentBatch>, submitted: int, budget_reached: bool}
     */
    public function submit(array $inputs, EnrichmentBudget $budget): array
    {
        $batches = [];
        $submitted = 0;
        $budgetReached = false;

        foreach (array_chunk($inputs, max(1, (int) config('services.place_enrichment.batch_size'))) as $chunk) {
            $requests = [];
            $estimate = 0.0;
            foreach ($chunk as $input) {
                $params = $this->enricher->requestParams($input);
                $cost = $budget->estimate($params, true);
                if (! $budget->spend($cost)) {
                    $budgetReached = true;

                    break;
                }
                $estimate += $cost;
                $requests[$input->spotId] = ['custom_id' => self::customId($input), 'params' => $params];
            }
            if ($requests === []) {
                break;
            }

            $response = $this->client()->post('/v1/messages/batches', ['requests' => array_values($requests)])->throw();
            $batches[] = SpotEnrichmentBatch::query()->create([
                'provider_batch_id' => (string) $response->json('id'),
                'status' => 'in_progress',
                'request_count' => count($requests),
                'spot_ids' => array_keys($requests),
                'estimated_cost_usd' => round($estimate, 6),
                'model' => (string) config('services.place_enrichment.model'),
                'prompt_version' => (string) config('services.place_enrichment.prompt_version'),
                'submitted_at' => now(),
            ]);
            $submitted += count($requests);

            if ($budgetReached) {
                break;
            }
        }

        return ['batches' => $batches, 'submitted' => $submitted, 'budget_reached' => $budgetReached];
    }

    /**
     * Applies every open batch that has ended. A batch still processing, or
     * whose results can't be fetched right now, waits for the next run.
     *
     * @return array<string, int> outcome counts
     */
    public function collect(): array
    {
        $outcomes = [];
        foreach (SpotEnrichmentBatch::query()->where('status', 'in_progress')->orderBy('id')->get() as $batch) {
            $status = $this->client()->get('/v1/messages/batches/'.rawurlencode($batch->provider_batch_id));
            if (! $status->successful() || $status->json('processing_status') !== 'ended') {
                continue;
            }

            // Fetched from the API host by id, never from a URL in a reply.
            $results = $this->client()->get('/v1/messages/batches/'.rawurlencode($batch->provider_batch_id).'/results');
            if ($results->status() === 404) {
                $batch->update(['status' => 'failed', 'collected_at' => now()]);

                continue;
            }
            if (! $results->successful()) {
                continue;
            }

            $counts = [];
            foreach (preg_split('/\r?\n/', trim($results->body())) ?: [] as $line) {
                $outcome = $this->applyLine($line);
                $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
                $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
            }
            $batch->update(['status' => 'collected', 'collected_at' => now(), 'result_counts' => $counts]);
        }

        return $outcomes;
    }

    public static function customId(PlaceEnrichmentInput $input): string
    {
        return 'spot-'.$input->spotId.'-'.substr($input->hash(), 0, 16);
    }

    private function applyLine(string $line): string
    {
        $row = json_decode($line, true);
        if (! is_array($row) || ! preg_match('/^spot-(\d+)-([0-9a-f]{16})$/', (string) ($row['custom_id'] ?? ''), $id)) {
            return 'unreadable';
        }
        $result = (array) ($row['result'] ?? []);
        $type = (string) ($result['type'] ?? 'unknown');
        $spot = Spot::query()->find((int) $id[1]);
        $input = $spot !== null ? PlaceEnrichmentInput::fromSpot($spot) : null;

        if ($type !== 'succeeded') {
            // errored, canceled or expired: not billed, nothing stored; the place goes in the next batch.
            $outcome = 'batch_'.$type.($type === 'errored' ? ':'.($result['error']['error']['type'] ?? 'unknown') : '');
            $this->enricher->record(PlaceEnricher::FEATURE_BATCH, 0, $outcome, null, true);
            if ($input !== null) {
                $this->enricher->noteFailedAttempt($input, $outcome);
            }

            return $outcome;
        }

        $message = (array) ($result['message'] ?? []);
        $outcome = match (true) {
            $input === null => 'place_gone',
            substr($input->hash(), 0, 16) !== $id[2] => 'stale_input',
            default => $this->enricher->applyReply($input, $message, PlaceEnricher::PRODUCER.':batch'),
        };
        $this->enricher->record(PlaceEnricher::FEATURE_BATCH, 0, $outcome, $message, true);

        return $outcome;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl('https://api.anthropic.com')
            ->timeout(60)
            ->connectTimeout(5)
            ->withHeaders(['x-api-key' => config('services.anthropic.key'), 'anthropic-version' => '2023-06-01']);
    }
}
