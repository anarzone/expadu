<?php

namespace App\Composer;

/**
 * One slot to fill in a day archetype: which categories qualify (empty = any),
 * how many of them, and whether it's the day's "hero" pick.
 */
final readonly class Role
{
    /**
     * @param  list<string>  $categories
     */
    public function __construct(
        public array $categories,
        public int $count,
        public bool $hero = false,
    ) {}
}
