<?php

namespace App\ContextEngine;

use App\Composer\Answers\AroundCheck;
use App\Models\User;
use App\Privacy\ExternalProcessingGate;
use App\Privacy\ProcessingOptIns;
use App\Privacy\ProcessingPurpose;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Http;

/**
 * The AI's part in a brief (owner, 26 Oct 2026: Haiku ranks, merges and writes, never inventing):
 * the items' own facts in, at most three lines out, each saying which items it says, each checked
 * against those items' facts as Composer's answers are (AroundCheck). A line that doesn't hold is
 * dropped; with the top items or a risk unsaid, the template writes the brief (BriefText).
 * Paperwork never goes to it: its line ("needs attention") is added after.
 *
 * Only for someone who turned AI-written briefs on (owner, 26 Oct 2026: opt-in, off by default):
 * each brief gets its own permission under their standing yes (ProcessingOptIns) and goes through
 * the gate like every request (ExternalProcessingGate); without it, the template writes.
 */
final class BriefWriter
{
    /** A line longer than this is cut on a phone. */
    private const MAX_LENGTH = 110;

    /**
     * What might happen or what they should do is never an item's fact (the brief eval, 26 Oct 2026:
     * "your way may be affected by the line 13 disruption", "check whether your way uses it").
     */
    /** Items about what threatens the day (DayBriefs): a brief always says them. */
    private const RISKS = ['trip_change', 'disruption', 'delay'];

    private const SPECULATION = '/\b(may|might|could|would|probably|likely|perhaps|maybe|possibly|check|consider|make sure|should|better|remember)\b/iu';

    private const SYSTEM = <<<'TEXT'
        You write the short push that tells a person in Cologne what matters in their day. You get numbered items, each with its facts. Write at most 3 lines, most important first: what threatens their day before what's merely on it. Merge items that are about the same thing into one line ("Line 13 is disrupted and your 08:10 way uses it" only when both items say line 13). Each line under 100 characters, plain words, no emoji, no greeting.
        Use only the items' facts: every time, line, name and number as written there. Never add a reason, a time, a place or a fact they don't say, never what might happen ("may be affected") or what they should do ("check", "consider"). Put two items in one line only when their facts share a time, a line number or a name; otherwise one item per line, and the 3 most important first. Say which items each line uses.
        TEXT;

    private const TOOL = ['name' => 'brief', 'description' => 'The brief, most important line first.',
        'input_schema' => ['type' => 'object', 'properties' => ['lines' => ['type' => 'array', 'maxItems' => 3, 'items' => ['type' => 'object', 'properties' => [
            'text' => ['type' => 'string'],
            'uses' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'the ids of the items this line says ("i1")'],
        ], 'required' => ['text', 'uses']]]], 'required' => ['lines']]];

    /** What each item a brief sends is, as its notice names it (ProcessingPurpose::BRIEF_WORDS). */
    public const CATEGORIES = ['trip' => 'your_planned_ways', 'trip_change' => 'your_planned_ways', 'event' => 'events_you_are_going_to',
        'plan' => 'your_plan_for_the_day', 'disruption' => 'transit_notices', 'delay' => 'transit_notices', 'weather' => 'weather', 'shops' => 'shops_closed'];

    public function __construct(private AroundCheck $check, private ProcessingOptIns $optIns, private ExternalProcessingGate $gate) {}

    /**
     * The brief's lines as the AI writes them for this person, under their own permission, or null
     * for the template.
     *
     * @param  list<array{id: string, kind: string, text: string, facts: string, own: bool, rank: int}>  $items
     * @return list<string>|null
     */
    public function write(User $user, array $items, CarbonImmutable $day, string $kind): ?array
    {
        $context = ['day' => $day->toDateString(), 'kind' => $kind, 'facts' => array_column(self::sendable($items), 'facts', 'id')];
        if (count($context['facts']) < 2) {
            return null;
        }
        $permit = $this->optIns->permit($user, ProcessingPurpose::NotificationBrief, $context);
        if ($permit === null) {
            return null;
        }

        return $this->compose($items, $day, $kind, fn (array $request): ?array => $this->gate->send($permit, ProcessingPurpose::NotificationBrief, $context,
            fn () => Http::baseUrl((string) config('services.llm.base_url'))
                ->withOptions(['allow_redirects' => false])
                ->withHeaders(['x-api-key' => (string) config('services.llm.key'), 'anthropic-version' => '2023-06-01'])
                ->timeout((int) config('services.llm.timeout', 8))->connectTimeout(3)
                ->post('/v1/messages', $request)));
    }

    /**
     * What may go to the AI: every item but Paperwork.
     *
     * @param  list<array{id: string, kind: string, text: string, facts: string, own: bool, rank: int}>  $items
     * @return list<array{id: string, kind: string, text: string, facts: string, own: bool, rank: int}>
     */
    public static function sendable(array $items): array
    {
        return array_values(array_filter($items, fn (array $item): bool => isset(self::CATEGORIES[$item['kind']])));
    }

    /**
     * Whether items a line says together are about the same thing: each shares a time, a number (a
     * line) or a named place with another. Rain at 14:00 and a plan from 12:00 share nothing, so a
     * line saying the plan is "affected" by it isn't kept.
     *
     * @param  list<string>  $facts
     */
    private static function linked(array $facts): bool
    {
        if (count($facts) < 2) {
            return true;
        }
        $marks = array_map(function (string $fact): array {
            preg_match_all('/\d+(?::\d+)?|"[^"]+"/u', $fact, $found);

            return $found[0];
        }, $facts);

        return collect($marks)->every(fn (array $mine, int $i): bool => collect($marks)->except($i)->contains(fn (array $theirs): bool => array_intersect($mine, $theirs) !== []));
    }

    /**
     * Rank, merge and write the items through `$send` (the request body in, the response body out),
     * keeping only the lines that hold.
     *
     * @param  list<array{id: string, kind: string, text: string, facts: string, own: bool, rank: int}>  $items
     * @param  Closure(array<string, mixed>): ?array<string, mixed>  $send
     * @return list<string>|null
     */
    public function compose(array $items, CarbonImmutable $day, string $kind, Closure $send): ?array
    {
        $paperwork = array_values(array_filter($items, fn (array $item): bool => $item['kind'] === 'paperwork'));
        $sent = self::sendable($items);
        if (count($sent) < 2) {
            return null;
        }
        $facts = array_column($sent, 'facts', 'id');
        $when = ($kind === DayBriefs::EVENING ? 'Tomorrow is ' : 'Today is ').$day->isoFormat('dddd D MMMM').'.';
        $body = $send([
            'model' => (string) config('services.llm.model'),
            'max_tokens' => 400,
            'system' => self::SYSTEM,
            'tools' => [self::TOOL],
            'tool_choice' => ['type' => 'tool', 'name' => 'brief'],
            'messages' => [['role' => 'user', 'content' => $when."\n".implode("\n", array_map(fn (string $id, string $fact): string => "{$id}: {$fact}", array_keys($facts), $facts))]],
        ]);
        $lines = collect($body['content'] ?? [])->first(fn (mixed $block): bool => is_array($block) && ($block['type'] ?? null) === 'tool_use')['input']['lines'] ?? null;
        if (! is_array($lines)) {
            return null;
        }

        $kept = [];
        $said = [];
        foreach (array_slice($lines, 0, BriefText::LINES) as $line) {
            $text = is_array($line) && is_string($line['text'] ?? null) ? trim($line['text']) : '';
            $uses = is_array($line['uses'] ?? null) ? array_values(array_intersect(array_filter($line['uses'], 'is_string'), array_keys($facts))) : [];
            $source = $when."\n".implode("\n", array_map(fn (string $id): string => $facts[$id], $uses));
            if ($text !== '' && $uses !== [] && mb_strlen($text) <= self::MAX_LENGTH && preg_match(self::SPECULATION, $text) !== 1
                && self::linked(array_map(fn (string $id): string => $facts[$id], $uses)) && $this->check->holds($text, $source, personal: true, named: count($uses))) {
                $kept[] = $text;
                $said = [...$said, ...$uses];
            }
        }
        // The most important items, and whatever threatens the day, are never lost with a line that
        // didn't hold: then the template says them.
        $needed = [...array_slice(array_column($sent, 'id'), 0, BriefText::LINES),
            ...array_column(array_filter($sent, fn (array $item): bool => in_array($item['kind'], self::RISKS, true)), 'id')];
        if ($kept === [] || array_diff($needed, $said) !== []) {
            return null;
        }

        return [...$kept, ...array_column($paperwork, 'text')];
    }
}
