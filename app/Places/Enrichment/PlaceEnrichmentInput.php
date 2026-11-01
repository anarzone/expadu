<?php

namespace App\Places\Enrichment;

use App\Enums\SpotCategory;
use App\Models\Spot;

/**
 * What the enricher knows about one place: its name, category, the OSM tags
 * that say something about it, and its source text. The snapshot (stored as
 * spot_enrichments.source_snapshot) and its hash cover every field sent to
 * the model and every tag a rule reads, so a changed tag re-runs the place.
 *
 * Addresses, contact details, prices, fees and opening hours are never sent:
 * the model can't put in the description what it never sees.
 */
final readonly class PlaceEnrichmentInput
{
    /** Tags that only name the kind of place; they don't count as detail. */
    private const TYPE_KEYS = ['amenity', 'leisure', 'tourism', 'shop', 'office', 'craft', 'club'];

    /** Tags that describe the place, sent to the model. */
    private const DETAIL_KEYS = [
        'cuisine', 'diet:vegetarian', 'diet:vegan', 'diet:halal', 'diet:kosher', 'diet:gluten_free', 'diet:lactose_free',
        'organic', 'fair_trade', 'breakfast', 'food', 'takeaway', 'outdoor_seating', 'indoor_seating', 'indoor', 'covered',
        'lit', 'sport', 'playground', 'playground:theme', 'kids_area', 'kids_area:indoor', 'changing_table', 'highchair',
        'baby_feeding', 'dog', 'internet_access', 'live_music', 'microbrewery', 'historic', 'heritage', 'memorial',
        'artwork_type', 'museum', 'museum_type', 'attraction', 'zoo', 'animal', 'natural', 'water', 'waterway', 'tower:type',
        'garden:type',
    ];

    /** Below this many detail values (and no source text) there is nothing to describe. */
    private const MIN_DETAILS = 2;

    /**
     * @param  array<string, string>  $osmTags  sent tags plus the tags rules read, sorted by key
     */
    public function __construct(
        public int $spotId,
        public string $name,
        public string $category,
        public string $categoryLabel,
        public bool $outdoorCategory,
        public array $osmTags,
        public ?string $sourceText,
    ) {}

    public static function fromSpot(Spot $spot): self
    {
        $category = $spot->category instanceof SpotCategory ? $spot->category : SpotCategory::tryFrom((string) $spot->category);
        $keep = array_flip([...self::TYPE_KEYS, ...self::DETAIL_KEYS, ...VibeTagRules::keys()]);
        $tags = [];
        foreach ((array) $spot->tags as $key => $value) {
            if (is_string($key) && isset($keep[$key]) && is_string($value) && trim($value) !== '') {
                $tags[$key] = mb_substr(trim($value), 0, 300);
            }
        }
        ksort($tags);

        $name = trim((string) $spot->name);
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $spot->description));
        // A description that only repeats the name says nothing.
        $text = $text === '' || mb_strtolower($text) === mb_strtolower($name) ? null : mb_substr($text, 0, 1000);

        return new self(
            spotId: (int) $spot->id,
            name: $name,
            category: $category?->value ?? (string) $spot->category,
            categoryLabel: $category?->label() ?? (string) $spot->category,
            outdoorCategory: $category?->isOutdoor() ?? false,
            osmTags: $tags,
            sourceText: $text,
        );
    }

    /** @return array<string, string> the tags the model sees */
    public function sentTags(): array
    {
        return array_intersect_key($this->osmTags, array_flip([...self::TYPE_KEYS, ...self::DETAIL_KEYS]));
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'name' => $this->name,
            'category' => $this->category,
            'osm_tags' => $this->osmTags,
            'source_text' => $this->sourceText,
        ];
    }

    public function hash(): string
    {
        return hash('sha256', (string) json_encode($this->snapshot(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Only a name and a category (or little more): no model call, summary unknown. */
    public function isThin(): bool
    {
        if ($this->sourceText !== null) {
            return false;
        }

        $details = 0;
        foreach (array_intersect_key($this->osmTags, array_flip(self::DETAIL_KEYS)) as $value) {
            $details += count(array_filter(explode(';', $value), fn (string $part): bool => trim($part) !== ''));
        }

        return $details < self::MIN_DETAILS;
    }

    /**
     * The user message: the place as untrusted data, plus the tags code has
     * already set from OSM so the description and tags agree with them.
     *
     * @param  list<string>  $knownTags
     */
    public function prompt(array $knownTags): string
    {
        $lines = ['<place>', '<name>'.$this->escape($this->name).'</name>', '<category>'.$this->escape($this->categoryLabel).'</category>', '<osm_tags>'];
        foreach ($this->sentTags() as $key => $value) {
            $lines[] = $this->escape($key.'='.$value);
        }
        $lines[] = '</osm_tags>';
        if ($this->sourceText !== null) {
            $lines[] = '<source_text>'.$this->escape($this->sourceText).'</source_text>';
        }
        $lines[] = '<known_tags>'.implode(', ', $knownTags).'</known_tags>';
        $lines[] = '</place>';

        return implode("\n", $lines);
    }

    /**
     * Lowercased text the linter grounds description words in: name,
     * category, tags, source text, plus "outdoor" for an outdoor category.
     */
    public function haystack(): string
    {
        $tags = array_map(fn (string $key, string $value): string => $key.'='.$value, array_keys($this->osmTags), $this->osmTags);

        return mb_strtolower(implode("\n", [
            $this->name, $this->category, $this->categoryLabel, ...$tags, (string) $this->sourceText,
            $this->outdoorCategory ? 'outdoor' : '',
        ]));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
    }
}
