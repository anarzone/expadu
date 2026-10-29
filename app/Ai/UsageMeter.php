<?php

namespace App\Ai;

use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Support\Facades\Event;

/**
 * Counts what the Messages API calls of one eval run cost: every reply's model, time and tokens,
 * cache reads and writes included, priced from services.llm_pricing (a cache read at a tenth of
 * the input price, a write at 1.25 times). For eval commands only; the app records its calls
 * in llm_calls (LlmCalls).
 */
final class UsageMeter
{
    /** @var list<array{model: string, ms: int, input: int, output: int, cache_read: int, cache_write: int, stop: ?string, error: ?string}> */
    private array $calls = [];

    public function start(): self
    {
        Event::listen(ResponseReceived::class, function (ResponseReceived $event): void {
            if (! str_ends_with($event->request->url(), '/v1/messages')) {
                return;
            }
            $body = $event->response->json();
            $usage = is_array($body['usage'] ?? null) ? $body['usage'] : [];
            $this->calls[] = [
                'model' => (string) ($body['model'] ?? ''),
                'ms' => (int) round(($event->response->handlerStats()['total_time'] ?? 0) * 1000),
                'input' => (int) ($usage['input_tokens'] ?? 0),
                'output' => (int) ($usage['output_tokens'] ?? 0),
                'cache_read' => (int) ($usage['cache_read_input_tokens'] ?? 0),
                'cache_write' => (int) ($usage['cache_creation_input_tokens'] ?? 0),
                'stop' => is_string($body['stop_reason'] ?? null) ? $body['stop_reason'] : ($event->response->successful() ? null : 'http_'.$event->response->status()),
                'error' => is_string($body['error']['message'] ?? null) ? mb_substr($body['error']['message'], 0, 300) : null,
            ];
        });

        return $this;
    }

    /** @return array{calls: int, cost_usd: ?float, ms_p50: int, ms_p95: int, tokens: array{input: int, output: int, cache_read: int, cache_write: int}, stops: array<string, int>, errors: list<string>} */
    public function summary(): array
    {
        $calls = collect($this->calls);
        // A refused request names no model and costs nothing.
        $billed = $calls->filter(fn (array $c) => $c['model'] !== '');
        $cost = $billed->every(fn (array $c) => is_array(config("services.llm_pricing.{$c['model']}"))) ? $billed->sum(function (array $c): float {
            $price = config("services.llm_pricing.{$c['model']}");

            return ($c['input'] + $c['cache_read'] * 0.1 + $c['cache_write'] * 1.25) / 1_000_000 * (float) $price['input_per_mtok']
                + $c['output'] / 1_000_000 * (float) $price['output_per_mtok'];
        }) : null;
        $ms = $calls->pluck('ms')->sort()->values();

        return [
            'calls' => $calls->count(),
            'cost_usd' => $cost === null ? null : round($cost, 5),
            'ms_p50' => (int) ($ms[(int) floor(max(0, $ms->count() - 1) * 0.5)] ?? 0),
            'ms_p95' => (int) ($ms[(int) floor(max(0, $ms->count() - 1) * 0.95)] ?? 0),
            'tokens' => ['input' => $calls->sum('input'), 'output' => $calls->sum('output'), 'cache_read' => $calls->sum('cache_read'), 'cache_write' => $calls->sum('cache_write')],
            'stops' => $calls->countBy(fn (array $c) => $c['stop'] ?? 'none')->all(),
            'errors' => $calls->pluck('error')->filter()->unique()->take(5)->values()->all(),
        ];
    }

    /** The ai.override an eval's --model, --effort and --max-tokens options set (AiRequest). */
    public static function override(?string $model, ?string $effort, ?int $maxTokens, array $more = []): void
    {
        config(['ai.override' => array_filter(['model' => $model, 'effort' => $effort, 'max_tokens' => $maxTokens, ...$more], fn ($v) => $v !== null)]);
    }
}
