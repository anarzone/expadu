<?php

namespace App\Home;

/**
 * One urgency-ranked tile on the Today screen. Tiles are sorted by score
 * (0–100, comparable with ActionBus scores) regardless of their source.
 */
final readonly class Tile
{
    public function __construct(
        public string $type,
        public string $title,
        public string $subtitle,
        public string $emoji,
        public string $severity, // danger | warn | info | neutral
        public float $score,
        public ?string $href = null,
        /** @var array<string, mixed> */
        public array $meta = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'emoji' => $this->emoji,
            'severity' => $this->severity,
            'score' => round($this->score, 1),
            'href' => $this->href,
            'meta' => $this->meta,
        ];
    }
}
