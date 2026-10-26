<?php

namespace App\ContextEngine;

use App\Composer\Answers\AroundCheck;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;

/**
 * The AI's part in a brief (owner, 26 Oct 2026: Haiku ranks, merges and writes, never inventing):
 * the items' own facts in, at most three lines out, each saying which items it says, each checked
 * against those items' facts as Composer's answers are (AroundCheck). A line that doesn't hold is
 * dropped; with none left, the template writes the brief (BriefText). Paperwork never goes to it:
 * its line ("needs attention") is added after.
 *
 * A person's day goes to the AI only with their permission for it, and nothing asks for that yet:
 * every AI call here is consented per request (ExternalProcessingGate), and a brief is no request
 * of theirs. Until the owner decides how people turn AI-written briefs on (with Settings, phase
 * 3), write() writes nothing and the template writes every brief; compose() is what it will run,
 * and what the brief eval (notifications:brief-eval) runs on made-up days.
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

    public function __construct(private AroundCheck $check) {}

    /**
     * The brief's lines as the AI writes them for this person, or null for the template.
     *
     * @param  list<array{id: string, kind: string, text: string, facts: string, own: bool, rank: int}>  $items
     * @return list<string>|null
     */
    public function write(User $user, array $items, CarbonImmutable $day, string $kind): ?array
    {
        // No permission for it exists yet (see above).
        return null;
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
        $sent = array_values(array_filter($items, fn (array $item): bool => $item['kind'] !== 'paperwork'));
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
