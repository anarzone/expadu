<?php

namespace App\Bureaucracy\Analyser;

use App\Bureaucracy\Verification\Figures;

/**
 * Decides, in code, which model sentences a person may read. A sentence survives only if:
 *  - it cites a card from this request's allow-list;
 *  - its quote is an exact excerpt of that card's checked text (whitespace aside);
 *  - every figure, amount, symbol, date, weekday, period, deadline, threshold or section
 *    word in it is also in its quote;
 *  - it asserts no legal outcome (entitled, must, can work, will get, applies to you…)
 *    and no fact about the person (you are, you have, because you…) unless the quote
 *    says it in those words;
 *  - it keeps the quote's negations and conditions (not, if, unless, only…);
 *  - apart from a small allow-list of grammar and attribution words ("The card says"),
 *    every word comes from the quote or the card's title, and it restates at least
 *    half of the quote's own words.
 * Anything else is dropped; the reason is kept for telemetry, never the text.
 */
final class AnswerVerifier
{
    public const MaxSentences = 4;

    private const MinQuoteWords = 3;

    private const MaxTextLength = 400;

    private const MaxQuoteLength = 600;

    private const QuoteCoverage = 0.5;

    /** Grammar and attribution only: none of these carries a fact on its own. */
    private const Glue = ['a', 'an', 'the', 'and', 'but', 'nor', 'so', 'yet', 'then', 'than', 'that', 'this', 'these', 'those', 'there',
        'here', 'it', 'its', "it's", 'they', 'them', 'their', 'theirs', 'he', 'him', 'his', 'she', 'her', 'hers', 'we', 'us', 'our', 'i',
        'me', 'my', 'you', 'your', 'yours', 'yourself', 'who', 'whom', 'whose', 'which', 'what', 'when', 'where', 'why', 'how', 'whether',
        'to', 'of', 'in', 'on', 'at', 'by', 'for', 'with', 'from', 'as', 'into', 'onto', 'about', 'through', 'up', 'out', 'off', 'per', 'via',
        'is', 'are', 'was', 'were', 'be', 'been', 'being', 'am', 'do', 'does', 'did', 'has', 'have', 'had', 'also', 'both', 'either', 'each',
        'other', 'another', 'such', 'same', 'very', 'too', 'again', 'further', 'once', 'while', 'because', 'although', 'though', 'however',
        'instead', 'rather', 'can', 'may', 'might', 'will', 'would', 'shall', 'could', 'should', 'must', 'not', 'no', 'if', 'unless', 'only',
        'except', 'or', 'card', 'cards', "card's", 'says', 'say', 'said', 'according', 'lists', 'list', 'listed', 'explains', 'explain',
        'notes', 'note', 'mentions', 'describes', 'states', 'advises', 'includes', 'include', 'adds', 'just', 'even', 'any', 'all', 'every',
        'some', 'own'];

    /** Figures, money, dates, periods, deadlines, thresholds and sections, as canonical name => pattern. */
    private const FigureWords = [
        'one' => 'one', 'two' => 'two', 'three' => 'three', 'four' => 'four', 'five' => 'five', 'six' => 'six', 'seven' => 'seven',
        'eight' => 'eight', 'nine' => 'nine', 'ten' => 'ten', 'eleven' => 'eleven', 'twelve' => 'twelve', 'thirteen' => 'thirteen',
        'fourteen' => 'fourteen', 'fifteen' => 'fifteen', 'sixteen' => 'sixteen', 'seventeen' => 'seventeen', 'eighteen' => 'eighteen',
        'nineteen' => 'nineteen', 'twenty' => 'twenty', 'thirty' => 'thirty', 'forty' => 'forty', 'fifty' => 'fifty', 'sixty' => 'sixty',
        'seventy' => 'seventy', 'eighty' => 'eighty', 'ninety' => 'ninety', 'hundred' => 'hundreds?', 'thousand' => 'thousands?',
        'million' => 'millions?', 'half' => 'half|halves', 'quarter' => 'quarters?', 'twice' => 'twice', 'double' => 'double', 'triple' => 'triple',
        'dozen' => 'dozens?', 'single' => 'single',
        'minute' => 'minutes?', 'hour' => 'hours?', 'day' => 'days?', 'week' => 'weeks?', 'fortnight' => 'fortnights?', 'month' => 'months?',
        'year' => 'years?', 'daily' => 'daily', 'weekly' => 'weekly', 'monthly' => 'monthly', 'yearly' => 'yearly', 'annual' => 'annual(?:ly)?',
        'quarterly' => 'quarterly', 'period' => 'periods?', 'weekday' => 'weekdays?', 'weekend' => 'weekends?',
        'monday' => 'mondays?', 'tuesday' => 'tuesdays?', 'wednesday' => 'wednesdays?', 'thursday' => 'thursdays?', 'friday' => 'fridays?',
        'saturday' => 'saturdays?', 'sunday' => 'sundays?', 'january' => 'january', 'february' => 'february', 'march' => 'march',
        'april' => 'april', 'june' => 'june', 'july' => 'july', 'august' => 'august', 'september' => 'september', 'october' => 'october',
        'november' => 'november', 'december' => 'december', 'today' => 'today', 'tomorrow' => 'tomorrow', 'yesterday' => 'yesterday',
        'tonight' => 'tonight',
        'deadline' => 'deadlines?', 'overdue' => 'overdue', 'expire' => 'expir(?:e|es|ed|ing|y|ation)', 'within' => 'within',
        'latest' => 'latest', 'later' => 'later', 'earlier' => 'earlier', 'late' => 'late', 'early' => 'early', 'soon' => 'soon(?:er|est)?',
        'immediately' => 'immediately', 'promptly' => 'promptly', 'delay' => 'delays?|delayed', 'time limit' => 'time limits?',
        'before' => 'before', 'after' => 'after(?:wards)?', 'until' => 'until|till', 'during' => 'during', 'since' => 'since', 'prior' => 'prior',
        'first' => 'first', 'second' => 'second', 'third' => 'third', 'last' => 'last', 'next' => 'next',
        'over' => 'over', 'under' => 'under', 'above' => 'above', 'below' => 'below', 'more' => 'more', 'most' => 'most', 'less' => 'less',
        'least' => 'least', 'fewer' => 'fewer', 'minimum' => 'minimum|min', 'maximum' => 'maximum|max', 'threshold' => 'thresholds?',
        'fee' => 'fees?', 'cost' => 'cost(?:s|ing)?', 'charge' => 'charg(?:e|es|ed|ing)', 'free' => 'free', 'gratis' => 'gratis',
        'price' => 'prices?', 'pay' => 'pa(?:y|ys|id|ying|yment|yments)', 'euro' => 'euros?|eur', 'cent' => 'cents?',
        'percent' => 'percent|per cent', 'salary' => 'salar(?:y|ies)', 'income' => 'incomes?', 'gross' => 'gross', 'net' => 'net',
        'section' => 'sections?', 'paragraph' => 'paragraphs?', 'article' => 'articles?', 'clause' => 'clauses?',
    ];

    private const Symbols = ['€', '$', '£', '%', '§', '/'];

    /** Negations: the count must match, so none is added or lost. */
    private const Negations = ['not', 'no', 'never', 'none', 'nothing', 'nobody', 'neither', 'nor', 'without', 'cannot', "can't", "don't",
        "doesn't", "isn't", "aren't", "won't", "didn't", "wasn't", "weren't", "haven't", "hasn't", "mustn't", "shouldn't", "needn't", "couldn't"];

    /** Conditions and limits: the count must match, so none is added or lost. */
    private const Conditions = ['if', 'unless', 'only', 'except', 'or'];

    /** Absolutes: only when the quote has them. */
    private const Absolutes = ['always', 'every', 'all', 'any', 'each', 'automatically', 'automatic', 'guaranteed', 'definitely',
        'certainly', 'surely', 'usually', 'normally', 'generally', 'often', 'rarely', 'sometimes', 'exempt'];

    /**
     * Phrases a sentence may only use when its quote says them in those terms.
     *
     * @var array<string, list<string>>
     */
    private const Assertions = [
        'legal_outcome' => [
            '/\b(?:entitled|eligible|ineligible|qualif(?:y|ies|ied|ying)|exempt(?:ed|ion)?|guarantee[sd]?|approved?|granted?|rejected|refused|denied)\b/u',
            "/\\b(?:must(?: not|n't)?|ha(?:ve|s|d) to|needs? to|needn't|need not|(?:do|does)(?: not|n't) need|requir(?:e|es|ed|ement|ements)|oblig(?:ed|ated|ation|atory)|mandatory|compulsory|optional|necessary)\\b/u",
            "/\\b(?:will|won't)(?:\\s+not)?\\s+[\\p{L}']+/u",
            "/\\b(?:can|cannot|can't|could|couldn't|may|might)(?:\\s+not)?\\s+[\\p{L}']+/u",
            "/\\b(?:should(?:n't| not)?|ought)\\b/u",
            '/\b(?:allowed|permitted|prohibited|forbidden|illegal|unlawful|lawful|legal right|rights?)\b/u',
            '/\b(?:meets?|met|fulfil(?:l|s|ls)?|satisf(?:y|ies|ied))\b/u',
            '/\b(?:appl(?:y|ies) to you|in your case|for your case|relevant to you|this applies|that applies|you are covered)\b/u',
        ],
        'case_fact' => [
            "/\\byou(?:'re|'ve|'d|'ll|\\s+(?:are|were|have|had|did|do|don't|haven't|aren't|already|still|currently|hold|held|live|lived|moved|arrived|registered|entered|work|worked|earn|earned|own|told|confirmed|said|answered|mentioned|chose|selected))\\b/u",
            '/\b(?:since|because|as|given that|now that)\s+you\b/u',
            '/\byour\s+(?:answers?|situation|case|status|citizenship|nationality|profile|plan)\b/u',
        ],
    ];

    /**
     * @param  list<mixed>  $sentences  the model's sentences, untrusted
     * @param  array<string, CheckedCard>  $cards  this request's allow-list
     * @return array{kept: list<array{text: string, card_id: string, quote: string}>, dropped: list<array{index: int, reason: string}>}
     */
    public function verify(array $sentences, array $cards): array
    {
        $kept = [];
        $dropped = [];
        $seen = [];
        foreach (array_values($sentences) as $index => $sentence) {
            $reason = $this->reason($sentence, $cards);
            if ($reason === null && isset($seen[AnswerText::key((string) $sentence['text'])])) {
                $reason = 'duplicate';
            }
            if ($reason === null && count($kept) >= self::MaxSentences) {
                $reason = 'too_many';
            }
            if ($reason !== null) {
                $dropped[] = ['index' => $index, 'reason' => $reason];

                continue;
            }
            $seen[AnswerText::key($sentence['text'])] = true;
            $kept[] = ['text' => AnswerText::whitespace($sentence['text']), 'card_id' => $sentence['card_id'], 'quote' => AnswerText::whitespace($sentence['quote'])];
        }

        return ['kept' => $kept, 'dropped' => $dropped];
    }

    /** @param array<string, CheckedCard> $cards */
    public function reason(mixed $sentence, array $cards): ?string
    {
        if (! is_array($sentence) || array_is_list($sentence) || ! $this->exactKeys($sentence)
            || ! is_string($sentence['text']) || ! is_string($sentence['card_id']) || ! is_string($sentence['quote'])) {
            return 'malformed';
        }
        $text = AnswerText::whitespace($sentence['text']);
        $quote = AnswerText::whitespace($sentence['quote']);
        if ($text === '' || mb_strlen($text) > self::MaxTextLength) {
            return 'malformed';
        }
        $card = $cards[$sentence['card_id']] ?? null;
        if (! $card instanceof CheckedCard) {
            return 'unknown_card';
        }
        if (mb_strlen($quote) > self::MaxQuoteLength || count(AnswerText::words($quote)) < self::MinQuoteWords) {
            return 'quote_length';
        }
        if (! collect($card->segments)->contains(fn (string $segment): bool => str_contains($segment, $quote))) {
            return 'quote_not_in_card';
        }
        if (preg_match('~https?://|www\.|\S@\S|<\s*/?\s*[a-z]|[\[\]{}*_`#|]~iu', $text) === 1) {
            return 'link_or_markup';
        }
        if (! $this->figuresSupported(AnswerText::key($text), AnswerText::key($quote))) {
            return 'figure_not_in_quote';
        }
        $assertion = $this->assertion(AnswerText::key($text), AnswerText::key($quote));
        if ($assertion !== null) {
            return $assertion;
        }
        if (! $this->sameLogic(AnswerText::words($text), AnswerText::words($quote))) {
            return 'negation_or_condition_changed';
        }
        if (! $this->grounded(AnswerText::words($text), AnswerText::words($quote), AnswerText::words($card->title))) {
            return 'ungrounded_words';
        }

        return null;
    }

    private function figuresSupported(string $text, string $quote): bool
    {
        $quoteTokens = Figures::tokens($quote);
        foreach (Figures::tokens($text) as $token) {
            if (! in_array($token, $quoteTokens, true)) {
                return false;
            }
        }
        preg_match_all('/\d+/u', $text, $textDigits);
        preg_match_all('/\d+/u', $quote, $quoteDigits);
        if (array_diff($textDigits[0], $quoteDigits[0]) !== []) {
            return false;
        }
        foreach (self::Symbols as $symbol) {
            if (str_contains($text, $symbol) && ! str_contains($quote, $symbol)) {
                return false;
            }
        }
        foreach (self::FigureWords as $pattern) {
            $regex = '/(?<![\p{L}\p{N}])(?:'.$pattern.')(?![\p{L}\p{N}])/u';
            if (preg_match($regex, $text) === 1 && preg_match($regex, $quote) !== 1) {
                return false;
            }
        }
        // "due" is a deadline word, "due to" is not.
        $due = '/\bdue\b(?!\s+to\b)/u';

        return ! (preg_match($due, $text) === 1 && preg_match($due, $quote) !== 1);
    }

    private function assertion(string $text, string $quote): ?string
    {
        foreach (self::Assertions as $category => $patterns) {
            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $text, $matches);
                foreach ($matches[0] as $phrase) {
                    if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($phrase, '/').'(?![\p{L}\p{N}])/u', $quote) !== 1) {
                        return $category;
                    }
                }
            }
        }

        return null;
    }

    /** @param list<string> $text @param list<string> $quote */
    private function sameLogic(array $text, array $quote): bool
    {
        $count = fn (array $words, array $set): int => count(array_filter($words, fn ($word) => in_array($word, $set, true)
            || ($set === self::Negations && str_ends_with($word, "n't"))));
        if ($count($text, self::Negations) !== $count($quote, self::Negations)) {
            return false;
        }
        foreach (self::Conditions as $word) {
            if ($count($text, [$word]) !== $count($quote, [$word])) {
                return false;
            }
        }
        foreach (self::Absolutes as $word) {
            if (in_array($word, $text, true) && ! in_array($word, $quote, true)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $text @param list<string> $quote @param list<string> $title */
    private function grounded(array $text, array $quote, array $title): bool
    {
        $pool = array_values(array_unique([...$quote, ...$title]));
        $content = fn (string $word): bool => ! in_array($word, self::Glue, true) && ! in_array($word, self::Negations, true)
            && preg_match('/^\d+$/', $word) !== 1;
        foreach ($text as $word) {
            if ($content($word) && ! collect($pool)->contains(fn ($known) => AnswerText::sameWord($word, $known))) {
                return false;
            }
        }
        $quoteContent = array_values(array_unique(array_filter($quote, $content)));
        if ($quoteContent === []) {
            return true;
        }
        $restated = count(array_filter($quoteContent, fn ($word) => collect($text)->contains(fn ($used) => AnswerText::sameWord($word, $used))));

        return $restated / count($quoteContent) >= self::QuoteCoverage;
    }

    private function exactKeys(array $sentence): bool
    {
        $keys = array_keys($sentence);
        sort($keys);

        return $keys === ['card_id', 'quote', 'text'];
    }
}
