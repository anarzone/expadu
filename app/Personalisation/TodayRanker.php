<?php

namespace App\Personalisation;

use App\Ai\AiRequest;
use App\Enums\EventCategory;
use App\Home\TodayDiscovery;
use App\Models\User;
use App\Privacy\ExternalProcessingGate;
use App\Privacy\ProcessingOptIns;
use App\Privacy\ProcessingPurpose;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Today's events and open places put in order by the AI (owner, 26 Oct 2026: "AI should be used
 * for personalization"): rules first, the AI only orders what passes. The candidates are what
 * Today shows anyway (TodayDiscovery: on today or still on, open now, around their plans, nothing
 * made up); the AI orders them from what personalisation learned (TasteFacts) and what they looked
 * at lately (TodayTaste), and may give one short why for a pick, grounded only in those facts
 * (TasteProfileWriter::grounded). One it leaves out keeps its place after; a why naming a kind or
 * Veedel the pick isn't, or too long to show, is left out; one why that isn't theirs, or a reply
 * that doesn't parse, and Today keeps its own order. AI ranks, never hides. Each ordering is its own request under their personalisation
 * (ProcessingOptIns), worked out in the background (RankToday) and kept for the day (TodayTaste::order).
 */
final class TodayRanker
{
    /** What each part of its request is, as its notice names it (ProcessingPurpose::TODAY_WORDS). */
    public const CATEGORIES = ['the_day', 'your_taste', 'recent_interests', 'todays_candidates'];

    /** Logged with each list it ordered (ListImpressions): change it when the prompt, tool or candidates change. */
    public const VERSION = 'haiku-1';

    private const MAX_WHY = 90;

    /** Whys shown at most, as the prompt asks: the first picks' that hold. */
    private const MAX_WHYS = 3;

    private const SYSTEM = <<<'TEXT'
        You put the events and places Today shows a person in Cologne in the order they'd most likely want, from what they lean to and what they looked at lately. Order every id you're given, each once: first those whose kind and neighbourhood match their leanings or recent looks, then the rest, and last any kind they avoid. For at most 3 picks that match, add a why under 70 characters saying which of their leanings it matches ("You've been looking at cafés in Ehrenfeld"): name only the pick's own kind as given in brackets and its own neighbourhood, never its name, no times, no numbers, no reasons of your own. Leave the why out when the pick's own kind or neighbourhood isn't one of their leanings.
        TEXT;

    private const TOOL = ['name' => 'order', 'description' => 'Today\'s events and places, the most fitting first.',
        'input_schema' => ['type' => 'object', 'properties' => [
            'events' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'why' => ['type' => 'string']], 'required' => ['id']]],
            'places' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'why' => ['type' => 'string']], 'required' => ['id']]],
        ], 'required' => ['events', 'places']]];

    public function __construct(private TodayDiscovery $discovery, private TasteFacts $taste, private TodayTaste $recent, private ProcessingOptIns $optIns, private ExternalProcessingGate $gate) {}

    /**
     * Works out today's order for them and keeps it for the day; null when Today keeps its own.
     *
     * @return array{events: list<string>, places: list<string>, why: array<string, string>}|null
     */
    public function rank(User $user): ?array
    {
        if (! PersonalisationWording::covers($user, 2)) {
            return null;
        }
        $now = CarbonImmutable::now('Europe/Berlin');

        return $this->rankFrom($user, self::candidates($this->discovery->for($user, $now)), $this->taste->for($user) ?? [], $now);
    }

    /**
     * The same, from Today's candidates and their taste facts as they are now.
     *
     * @param  array{events: list<array<string, mixed>>, places: list<array<string, mixed>>}  $candidates
     * @param  array<string, mixed>  $facts  TasteFacts::for
     * @return array{events: list<string>, places: list<string>, why: array<string, string>}|null
     */
    public function rankFrom(User $user, array $candidates, array $facts, CarbonImmutable $now): ?array
    {
        if (! PersonalisationWording::covers($user, 2) || count($candidates['events']) + count($candidates['places']) < 2) {
            return null;
        }
        $recent = $this->recent->for($user);
        $context = ['day' => $now->format('Y-m-d H:i'), 'facts' => $facts === [] ? [] : TasteFacts::forModel($facts),
            // Other is what nothing else fits, never a leaning (TasteFacts::categoryLabel).
            'recent' => ['categories' => array_slice(array_values(array_diff($recent['categories'], [EventCategory::Other->value])), 0, 5), 'areas' => array_slice($recent['areas'], 0, 3)], 'candidates' => $candidates];
        $permit = $this->optIns->permit($user, ProcessingPurpose::TodayRank, $context);
        if ($permit === null) {
            return null;
        }
        $order = $this->compose($context, fn (array $request): ?array => $this->gate->send($permit, ProcessingPurpose::TodayRank, $context,
            fn () => Http::baseUrl((string) config('services.llm.base_url'))
                ->withOptions(['allow_redirects' => false])
                ->withHeaders(['x-api-key' => (string) config('services.llm.key'), 'anthropic-version' => '2023-06-01'])
                ->timeout((int) config('services.llm.timeout', 8))->connectTimeout(3)
                ->post('/v1/messages', $request)));
        Cache::put(TodayTaste::orderKey($user->id, $now), $order === null ? null : [...$order, 'at' => $now->toIso8601String(), 'version' => self::VERSION], $now->endOfDay());

        return $order;
    }

    /**
     * What Today shows that may be put in order: its events and its open places around the next plan.
     *
     * @return array{events: list<array{id: string, name: string, kind: string, area: ?string, start: ?string}>, places: list<array{id: string, name: string, kind: string, area: ?string, start: null}>}
     */
    public static function candidates(array $shown): array
    {
        $events = collect($shown['events'] ?? [])->filter(fn (mixed $e): bool => is_array($e) && isset($e['id'], $e['occurrence_start']))
            ->map(fn (array $e): array => ['id' => 'e'.$e['id'].'@'.CarbonImmutable::parse($e['occurrence_start'])->getTimestamp(), 'name' => (string) ($e['title'] ?? ''),
                'kind' => (string) ($e['category_label'] ?? $e['category'] ?? 'event'), 'area' => $e['venue']['veedel'] ?? $e['veedel'] ?? null,
                'start' => CarbonImmutable::parse($e['occurrence_start'])->setTimezone('Europe/Berlin')->format('H:i')])->values()->all();
        $places = collect($shown['around']['places'] ?? [])->filter(fn (mixed $p): bool => is_array($p) && isset($p['id']))
            ->map(fn (array $p): array => ['id' => 'p'.$p['id'], 'name' => (string) ($p['name'] ?? ''), 'kind' => (string) ($p['label'] ?? $p['kind'] ?? 'place'),
                'area' => $p['area'] ?? $p['veedel'] ?? null, 'start' => null])->values()->all();

        return ['events' => $events, 'places' => $places];
    }

    /**
     * Order the candidates through `$send` (the request body in, the response body out), keeping
     * the order only if every why holds.
     *
     * @param  array{day: string, facts: array<string, mixed>, recent: array{categories: list<string>, areas: list<string>}, candidates: array{events: list<array<string, mixed>>, places: list<array<string, mixed>>}}  $context
     * @param  Closure(array<string, mixed>): ?array<string, mixed>  $send
     * @return array{events: list<string>, places: list<string>, why: array<string, string>}|null
     */
    public function compose(array $context, Closure $send): ?array
    {
        $facts = $context['facts'];
        $leans = fn (string $key): string => implode(', ', array_map(fn (array $row): string => $row['level'] === 'avoid' ? "not {$row['name']}" : $row['name'], $facts[$key] ?? []));
        $recentKinds = array_values(array_filter(array_map(fn (string $kind): ?string => TasteFacts::categoryLabel($kind) ?? $kind, $context['recent']['categories'])));
        $line = fn (array $c): string => "{$c['id']}: \"{$c['name']}\" ({$c['kind']})".($c['area'] ? " in {$c['area']}" : '').($c['start'] ? " at {$c['start']}" : '');
        $body = $send(AiRequest::shape([
            'model' => (string) config('services.llm.model'),
            'max_tokens' => 600,
            'system' => self::SYSTEM,
            'tools' => [self::TOOL],
            'tool_choice' => ['type' => 'tool', 'name' => 'order'],
            'messages' => [['role' => 'user', 'content' => implode("\n", array_filter([
                'Now: '.CarbonImmutable::parse($context['day'], 'Europe/Berlin')->isoFormat('dddd D MMMM, HH:mm').'.',
                'They lean to: kinds '.($leans('categories') ?: 'none known').'; neighbourhoods '.($leans('veedels') ?: 'none known')
                    .($facts['dayparts'] ?? [] ? '; times '.implode(', ', $facts['dayparts']) : '').(($facts['week'] ?? null) ? '; '.$facts['week'] : '').'.',
                'Lately they looked at: '.($recentKinds !== [] ? implode(', ', $recentKinds) : 'nothing in particular')
                    .($context['recent']['areas'] !== [] ? '; in '.implode(', ', $context['recent']['areas']) : '').'.',
                $context['candidates']['events'] !== [] ? "Events:\n".implode("\n", array_map($line, $context['candidates']['events'])) : null,
                $context['candidates']['places'] !== [] ? "Places:\n".implode("\n", array_map($line, $context['candidates']['places'])) : null,
            ]))]],
        ]));
        $input = collect($body['content'] ?? [])->first(fn (mixed $block): bool => is_array($block) && ($block['type'] ?? null) === 'tool_use')['input'] ?? null;
        if (! is_array($input)) {
            return null;
        }

        // The why may say only their own leanings and recent interests, about the pick's own kind or Veedel.
        $grounding = ['categories' => [...($facts['categories'] ?? []), ...array_map(fn (string $name): array => ['name' => $name, 'level' => 'some'], $recentKinds)],
            'veedels' => [...($facts['veedels'] ?? []), ...array_map(fn (string $name): array => ['name' => $name, 'level' => 'some'], $context['recent']['areas'])],
            'dayparts' => $facts['dayparts'] ?? [], 'week' => $facts['week'] ?? null];
        $order = ['why' => []];
        foreach (['events', 'places'] as $list) {
            $byId = collect($context['candidates'][$list])->keyBy('id');
            $picked = [];
            foreach (is_array($input[$list] ?? null) ? $input[$list] : [] as $pick) {
                $id = is_array($pick) && is_string($pick['id'] ?? null) ? $pick['id'] : null;
                // An event's id without its "@start" names it while only one start of it is listed.
                if ($id !== null && ! $byId->has($id)) {
                    $starts = $byId->keys()->filter(fn (string $key): bool => str_starts_with($key, $id.'@'));
                    $id = $starts->count() === 1 ? $starts->first() : null;
                }
                if ($id === null || ! $byId->has($id) || in_array($id, $picked, true)) {
                    continue;
                }
                $picked[] = $id;
                $why = is_string($pick['why'] ?? null) ? trim($pick['why']) : '';
                // Too long to show: no why for it, the order stands.
                if ($why === '' || mb_strlen($why) > self::MAX_WHY) {
                    continue;
                }
                // A why that isn't theirs: Today keeps its own order.
                if (! TasteProfileWriter::grounded($why, $grounding)) {
                    return null;
                }
                // Theirs, but naming a kind or Veedel the pick isn't ("museums" for an exhibition): no why for it.
                if (count($order['why']) < self::MAX_WHYS && self::about($why, $byId[$id], $grounding)) {
                    $order['why'][$id] = $why;
                }
            }
            // Never hidden: what it left out keeps its place, after.
            $order[$list] = [...$picked, ...array_values(array_diff($byId->keys()->all(), $picked))];
        }

        return $order;
    }

    /** A why speaks of the pick: any kind or Veedel of theirs it names is the pick's own. */
    private static function about(string $why, array $pick, array $grounding): bool
    {
        $said = mb_strtolower($why);
        $stem = fn (string $word): string => (string) preg_replace(['/é/u', '/(es|s)$/u'], ['e', ''], mb_strtolower(trim($word)));
        foreach (array_column($grounding['veedels'], 'name') as $veedel) {
            if (str_contains($said, mb_strtolower($veedel)) && mb_strtolower((string) $pick['area']) !== mb_strtolower($veedel)) {
                return false;
            }
        }
        foreach (array_column($grounding['categories'], 'name') as $kind) {
            if (preg_match('/(?<!\p{L})'.preg_quote($stem($kind), '/').'/u', $stem($said)) === 1 && ! str_contains($stem((string) $pick['kind']), $stem($kind))) {
                return false;
            }
        }

        return true;
    }
}
