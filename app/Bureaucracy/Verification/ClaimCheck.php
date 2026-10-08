<?php

namespace App\Bureaucracy\Verification;

/**
 * Automated source check for one catalogue card.
 *
 * Each claim ties a piece of the card's text to the exact sentence on an official
 * page that supports it. The check guarantees three things:
 *  - every figure the user can read (durations, money, section references,
 *    language levels, any other number) sits inside a claimed piece of text;
 *  - every figure in a claim also appears in its quote, so a number cannot drift
 *    from its source ("14 days" needs "zwei Wochen" or "14 Tagen");
 *  - the quote is still on the official page (checked online).
 *
 * It does not prove that unnumbered prose is a correct reading of the law; that
 * judgement is made once, when the claim is written.
 */
final class ClaimCheck
{
    public const Deadline = 'deadline';

    private const DatedDeadlines = ['days_since_arrival', 'days_since_move_in', 'days_since_event', 'permit_window'];

    /** Section references must appear in the opening of the cited law page (its heading). */
    private const HeadingBytes = 400;

    /**
     * Structural problems in the authored claims. No network.
     *
     * @param  array<string, mixed>  $card
     * @return list<string>
     */
    public function shapeErrors(array $card): array
    {
        $claims = $card['claims'] ?? null;
        if (! is_array($claims) || $claims === [] || ! array_is_list($claims)) {
            return ['claims must be a non-empty list'];
        }
        $labels = [];
        foreach ($card['legal_sources'] ?? [] as $source) {
            if (is_array($source) && is_string($source['label'] ?? null)) {
                if (isset($labels[$source['label']])) {
                    return ['legal_sources labels must be unique so claims can cite them'];
                }
                $labels[$source['label']] = $source['kind'] ?? null;
            }
        }
        $errors = [];
        $ids = [];
        $primary = false;
        foreach ($claims as $index => $claim) {
            $prefix = "claims.{$index}";
            if (! is_array($claim)) {
                $errors[] = "{$prefix} must be an object";

                continue;
            }
            $id = $claim['id'] ?? null;
            if (! is_string($id) || ! preg_match('/^[a-z0-9][a-z0-9-]*$/D', $id) || isset($ids[$id])) {
                $errors[] = "{$prefix}.id must be a unique lowercase slug";
            }
            $ids[$id] = true;
            $hasStates = is_string($claim['states'] ?? null) && trim($claim['states']) !== '';
            $covers = $claim['covers'] ?? null;
            if ($hasStates === ($covers !== null) || ($covers !== null && $covers !== self::Deadline)) {
                $errors[] = "{$prefix} needs exactly one of `states` (card text) or `covers: deadline`";
            }
            if (! is_string($claim['quote'] ?? null) || mb_strlen(SourceText::clean($claim['quote'])) < 12) {
                $errors[] = "{$prefix}.quote must be the exact source sentence (at least 12 characters)";
            }
            $source = $claim['source'] ?? null;
            if (! is_string($source) || ! array_key_exists($source, $labels)) {
                $errors[] = "{$prefix}.source must name one of this card's legal_sources labels";
            } elseif ($labels[$source] === 'primary') {
                $primary = true;
            }
        }
        if (! $primary && $errors === []) {
            $errors[] = 'at least one claim must quote a primary legal source';
        }

        return $errors;
    }

    /**
     * Everything except "is the quote still on the page". No network.
     *
     * @param  array<string, mixed>  $card
     * @return list<string>
     */
    public function offlineErrors(array $card): array
    {
        $errors = $this->shapeErrors($card);
        if ($errors !== []) {
            return $errors;
        }
        $segments = array_map(fn ($text) => SourceText::key($this->withoutConfiguredFigures($text)), $this->visibleText($card));
        $claims = $card['claims'];

        foreach ($claims as $claim) {
            if (! isset($claim['states'])) {
                continue;
            }
            $states = SourceText::key($claim['states']);
            if (! collect($segments)->contains(fn ($segment) => str_contains($segment, $states))) {
                $errors[] = "claim `{$claim['id']}`: its text is not on the card: \"{$claim['states']}\"";

                continue;
            }
            $quote = Figures::tokens(SourceText::key($claim['quote']));
            foreach (Figures::tokens($states) as $token) {
                // A section reference may instead be checked against the cited page's heading.
                if (! $this->supported($token, $quote) && ! str_starts_with($token, 'section:')) {
                    $errors[] = "claim `{$claim['id']}`: \"".$this->describe($token).'" is not in its quote';
                }
            }
        }

        foreach ($segments as $segment) {
            $covered = [];
            foreach ($claims as $claim) {
                if (! isset($claim['states'])) {
                    continue;
                }
                $needle = SourceText::key($claim['states']);
                for ($at = strpos($segment, $needle); $at !== false; $at = strpos($segment, $needle, $at + 1)) {
                    $covered[] = [$at, $at + strlen($needle)];
                }
            }
            foreach (Figures::in($segment) as $figure) {
                $inside = collect($covered)->contains(fn ($span) => $span[0] <= $figure['start'] && $figure['end'] <= $span[1]);
                if (! $inside) {
                    $errors[] = 'unclaimed figure "'.substr($segment, $figure['start'], $figure['end'] - $figure['start']).'" in: "'.$this->excerpt($segment, $figure['start']).'"';
                }
            }
        }

        $days = $card['deadline_days'] ?? null;
        $type = $this->value($card['deadline_type'] ?? null);
        if ($days !== null && in_array($type, self::DatedDeadlines, true)) {
            $token = 'duration:days:'.(int) $days;
            $backed = collect($claims)->contains(fn ($claim) => ($claim['covers'] ?? null) === self::Deadline
                && in_array($token, Figures::tokens(SourceText::key($claim['quote'])), true));
            if (! $backed) {
                $errors[] = "the {$days}-day deadline needs a `covers: deadline` claim whose quote states that period";
            }
        } elseif (collect($claims)->contains(fn ($claim) => ($claim['covers'] ?? null) === self::Deadline)) {
            $errors[] = 'a `covers: deadline` claim needs a dated deadline (deadline_type with deadline_days)';
        }

        return array_values(array_unique($errors));
    }

    /**
     * The full check. `$pages` maps each source URL to its fetch result.
     *
     * @param  array<string, mixed>  $card
     * @param  array<string, array{status: string, text?: string, reason?: string}>  $pages
     * @return array{outcome: 'passed'|'failed'|'unreachable', failures: list<string>, unreachable: list<string>}
     */
    public function check(array $card, array $pages): array
    {
        $failures = $this->offlineErrors($card);
        $unreachable = [];
        if ($failures === []) {
            $urls = [];
            foreach ($card['legal_sources'] ?? [] as $source) {
                $urls[$source['label']] = $source['url'];
            }
            foreach ($card['claims'] as $claim) {
                $url = $urls[$claim['source']];
                $page = $pages[$url] ?? ['status' => 'unreachable', 'reason' => 'not_fetched'];
                if ($page['status'] === 'missing') {
                    $failures[] = "claim `{$claim['id']}`: the source page is gone ({$url})";

                    continue;
                }
                if ($page['status'] !== 'ok') {
                    $unreachable[] = $url;

                    continue;
                }
                $text = SourceText::key($page['text'] ?? '');
                if (! str_contains($text, SourceText::key($claim['quote']))) {
                    $failures[] = "claim `{$claim['id']}`: the quote is no longer on {$claim['source']}";

                    continue;
                }
                if (isset($claim['states'])) {
                    $quote = Figures::tokens(SourceText::key($claim['quote']));
                    $heading = Figures::tokens(substr($text, 0, self::HeadingBytes));
                    foreach (Figures::tokens(SourceText::key($claim['states'])) as $token) {
                        if (str_starts_with($token, 'section:') && ! in_array($token, $quote, true) && ! in_array($token, $heading, true)) {
                            $failures[] = "claim `{$claim['id']}`: \"".$this->describe($token)."\" is not the section cited by {$claim['source']}";
                        }
                    }
                }
            }
        }
        $outcome = $failures !== [] ? 'failed' : ($unreachable !== [] ? 'unreachable' : 'passed');

        return ['outcome' => $outcome, 'failures' => array_values(array_unique($failures)), 'unreachable' => array_values(array_unique($unreachable))];
    }

    /**
     * Every piece of text a person can read on this card.
     *
     * @param  array<string, mixed>  $card
     * @return list<string>
     */
    public function visibleText(array $card): array
    {
        $texts = [$card['title'] ?? null, $card['description'] ?? null];
        foreach ($card['description_variants'] ?? [] as $variant) {
            $texts[] = is_array($variant) ? ($variant['body'] ?? null) : null;
        }
        foreach ($card['how_to_steps'] ?? [] as $step) {
            $texts[] = is_array($step) ? ($step['title'] ?? null) : $step;
            $texts[] = is_array($step) ? ($step['body'] ?? null) : null;
        }
        foreach ($card['documents_required'] ?? [] as $document) {
            $texts[] = is_array($document) ? ($document['label'] ?? null) : $document;
            $texts[] = is_array($document) ? ($document['note'] ?? null) : null;
        }
        foreach ($card['decision_options'] ?? [] as $option) {
            $texts[] = is_array($option) ? ($option['label'] ?? null) : $option;
        }

        return array_values(array_filter($texts, fn ($text) => is_string($text) && trim($text) !== ''));
    }

    /** Figures from config/bureaucracy_figures.php carry their own source and verification date. */
    private function withoutConfiguredFigures(string $text): string
    {
        $text = preg_replace('/\{\{figure:[a-z0-9_]+\}\}/', ' ', $text) ?? $text;
        foreach (config('bureaucracy_figures', []) as $figure) {
            if (is_array($figure) && is_string($figure['value'] ?? null) && $figure['value'] !== '') {
                $text = str_replace($figure['value'], ' ', $text);
            }
        }

        return $text;
    }

    /** A bare number ("fewer than 21 documented months") is backed by the same number in any quoted figure. */
    private function supported(string $token, array $quote): bool
    {
        if (in_array($token, $quote, true)) {
            return true;
        }
        if (! str_starts_with($token, 'number:')) {
            return false;
        }
        $number = substr($token, strlen('number:'));

        return collect($quote)->contains(fn ($other) => preg_match('/:'.preg_quote($number, '/').'$/', $other) === 1
            && ! str_starts_with($other, 'section:'));
    }

    private function describe(string $token): string
    {
        [$kind, $value] = explode(':', $token, 2);

        return match ($kind) {
            'duration' => str_replace(['days:', 'months:', 'hours:', 'workdays:'], ['', '', '', ''], $value).' '.strtok($value, ':'),
            'section' => '§'.$value,
            'money' => '€'.$value,
            default => $value,
        };
    }

    private function excerpt(string $segment, int $at): string
    {
        $start = max(0, $at - 40);

        return trim(mb_strcut($segment, $start, 100, 'UTF-8'));
    }

    private function value(mixed $value): ?string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (is_string($value) ? $value : null);
    }
}
