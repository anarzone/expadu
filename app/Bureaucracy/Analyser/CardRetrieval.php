<?php

namespace App\Bureaucracy\Analyser;

/**
 * Picks the candidate cards for a question, in code: a keyword and trigram match of
 * the question over each card's title and checked text, weighted by how rare the
 * word is across cards, plus the cards that apply to the case. Cards the question
 * does not touch are added only when too few match, so unrelated cards (and the
 * answers their conditions read) are not sent along.
 */
final class CardRetrieval
{
    public const Cap = 8;

    private const MinScore = 0.2;

    /** With fewer text matches than this, the case's applying cards fill in. */
    private const FewMatches = 3;

    private const FillTo = 4;

    private const Stopwords = ['the', 'and', 'for', 'are', 'but', 'not', 'you', 'your', 'yours', 'with', 'have', 'has', 'had', 'was', 'were',
        'what', 'which', 'when', 'where', 'who', 'whom', 'why', 'how', 'can', 'could', 'should', 'would', 'will', 'shall', 'may', 'might',
        'must', 'does', 'did', 'doing', 'done', 'this', 'that', 'these', 'those', 'there', 'here', 'from', 'into', 'about', 'after', 'before',
        'any', 'all', 'some', 'our', 'out', 'get', 'got', 'need', 'needs', 'want', 'wants', 'know', 'tell', 'please', 'mine', 'myself',
        'its', 'they', 'them', 'their', 'she', 'her', 'his', 'him', 'also', 'just', 'than', 'then', 'too', 'very', 'yes', 'okay', 'still',
        'now', 'much', 'many', 'long', 'way', 'one', 'like', 'make', 'take', 'give', 'help', 'there', 'is', 'am', 'do', 'my', 'me', 'it', 'of',
        'to', 'in', 'on', 'at', 'by', 'or', 'if', 'so', 'an', 'as', 'be', 'we', 'us', 'he', 'up', 'no', 'a', 'i'];

    /** Short words that still carry meaning. */
    private const ShortTerms = ['eu', 'id', 'tax', 'job', 'fee', 'kid', 'visa'];

    /** Everyday words for the cards' own vocabulary (retrieval only; never shown). */
    private const Synonyms = [
        'wife' => ['spouse', 'family', 'married'], 'husband' => ['spouse', 'family', 'married'], 'partner' => ['spouse', 'family'],
        'spouse' => ['family', 'married'], 'kids' => ['child', 'children'], 'kid' => ['child', 'children'], 'baby' => ['child', 'birth'],
        'son' => ['child'], 'daughter' => ['child'], 'work' => ['employment', 'job'], 'job' => ['employment', 'work'],
        'cost' => ['fee', 'costs', 'charge'], 'price' => ['fee', 'cost'], 'pay' => ['fee', 'payment'], 'costs' => ['fee', 'cost'],
        'register' => ['registration', 'anmeldung'], 'address' => ['registration', 'anmeldung'], 'flat' => ['dwelling', 'housing'],
        'apartment' => ['dwelling', 'housing'], 'landlord' => ['wohnungsgeber', 'housing'], 'documents' => ['document', 'papers'],
        'papers' => ['documents'], 'bring' => ['documents'], 'move' => ['moving', 'einzug'], 'moved' => ['moving', 'einzug'],
        'health' => ['insurance'], 'insurance' => ['insurer', 'health'], 'doctor' => ['health', 'insurance'],
        'permit' => ['residence', 'title'], 'visa' => ['residence', 'permit'], 'stay' => ['residence'], 'permanent' => ['settlement'],
        'freelance' => ['freelancer', 'self-employed'], 'business' => ['gewerbe', 'freelancer'], 'study' => ['student', 'university'],
        'bank' => ['account'], 'tax' => ['steuer'], 'deadline' => ['period', 'weeks'],
    ];

    /**
     * @param  array<string, CheckedCard>  $cards
     * @return list<CheckedCard> most relevant first, at most Cap
     */
    public function candidates(string $question, array $cards): array
    {
        if ($cards === []) {
            return [];
        }
        $scores = $this->scores($question, $cards);
        arsort($scores);
        $picked = [];
        foreach ($scores as $id => $score) {
            if ($score >= self::MinScore && count($picked) < self::Cap) {
                $picked[$id] = $cards[$id];
            }
        }
        if (count($picked) < self::FewMatches) {
            $applying = array_filter($cards, fn (CheckedCard $card) => $card->appliesToCase === true && ! isset($picked[$card->id]));
            uasort($applying, fn (CheckedCard $a, CheckedCard $b) => $b->urgencyRank <=> $a->urgencyRank ?: strcmp($a->id, $b->id));
            foreach ($applying as $card) {
                if (count($picked) >= self::FillTo) {
                    break;
                }
                $picked[$card->id] = $card;
            }
        }

        return array_values($picked);
    }

    /**
     * @param  array<string, CheckedCard>  $cards
     * @return array<string, float>
     */
    public function scores(string $question, array $cards): array
    {
        $groups = $this->termGroups($question);
        $title = [];
        $body = [];
        $vocabulary = [];
        foreach ($cards as $id => $card) {
            $title[$id] = array_flip(AnswerText::words($card->title));
            $body[$id] = array_flip(AnswerText::words(implode(' ', $card->segments)));
            $vocabulary += $title[$id] + $body[$id];
        }
        $trigrams = [];
        foreach (array_keys($vocabulary) as $word) {
            $trigrams[(string) $word] = $this->trigrams((string) $word);
        }
        // How closely each question term matches each word any card uses.
        $matches = [];
        foreach ($groups as $alternatives) {
            foreach ($alternatives as $term => $weight) {
                $termTrigrams = $this->trigrams($term);
                foreach ($trigrams as $word => $wordTrigrams) {
                    $similarity = $this->similarity($term, $word, $termTrigrams, $wordTrigrams);
                    if ($similarity > 0) {
                        $matches[$term][$word] = $similarity * $weight;
                    }
                }
            }
        }
        $count = count($cards);
        $scores = array_fill_keys(array_keys($cards), 0.0);
        $norm = 0.0;
        foreach ($groups as $alternatives) {
            $per = [];
            foreach ($cards as $id => $card) {
                $inTitle = 0.0;
                $inBody = 0.0;
                foreach (array_keys($alternatives) as $term) {
                    foreach ($matches[$term] ?? [] as $word => $similarity) {
                        if (isset($title[$id][$word])) {
                            $inTitle = max($inTitle, $similarity);
                        }
                        if (isset($body[$id][$word])) {
                            $inBody = max($inBody, $similarity);
                        }
                    }
                }
                $per[$id] = max($inTitle, 0.6 * $inBody);
            }
            $holders = count(array_filter($per, fn ($value) => $value > 0));
            $idf = log(($count + 1) / ($holders + 0.5));
            $norm += $idf;
            foreach ($per as $id => $value) {
                $scores[$id] += $idf * $value;
            }
        }
        foreach ($cards as $id => $card) {
            $score = $norm > 0 ? $scores[$id] / $norm : 0.0;
            $ruledOut = in_array('unmet', array_column($card->criteria, 'status'), true);
            $scores[$id] = round(($ruledOut ? 0.75 * $score : $score) + match ($card->appliesToCase) {
                true => 0.1, null => 0.05, false => 0.0,
            } * ($score > 0 ? 1 : 0), 4);
        }

        return $scores;
    }

    /** @return list<array<string, float>> each group: the question's word and its everyday alternatives, with weights */
    private function termGroups(string $question): array
    {
        $groups = [];
        foreach (array_unique(AnswerText::words($question)) as $word) {
            if (in_array($word, self::Stopwords, true) || (mb_strlen($word) < 4 && ! in_array($word, self::ShortTerms, true))) {
                continue;
            }
            $group = [$word => 1.0];
            foreach (self::Synonyms[$word] ?? [] as $alternative) {
                $group[$alternative] ??= 0.85;
            }
            $groups[] = $group;
        }

        return $groups;
    }

    private function similarity(string $term, string $word, array $termTrigrams, array $wordTrigrams): float
    {
        if ($term === $word) {
            return 1.0;
        }
        if (AnswerText::sameWord($term, $word)) {
            return 0.9;
        }
        // German compounds: "wohnungsgeber" inside "wohnungsgeberbescheinigung".
        if (mb_strlen($term) >= 5 && mb_strlen($word) >= 5 && (str_contains($word, $term) || (mb_strlen($word) >= 6 && str_contains($term, $word)))) {
            return 0.8;
        }
        $shared = count(array_intersect_key($termTrigrams, $wordTrigrams));
        $union = count($termTrigrams + $wordTrigrams);
        $jaccard = $union === 0 ? 0.0 : $shared / $union;

        return $jaccard >= 0.5 ? $jaccard : 0.0;
    }

    /** @return array<string, true> pg_trgm-style trigrams of one padded word */
    private function trigrams(string $word): array
    {
        $padded = '  '.$word.' ';
        $result = [];
        for ($index = 0, $length = mb_strlen($padded); $index + 3 <= $length; $index++) {
            $result[mb_substr($padded, $index, 3)] = true;
        }

        return $result;
    }
}
