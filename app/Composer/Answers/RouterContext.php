<?php

namespace App\Composer\Answers;

/**
 * The few facts RequestRouter needs besides the words: which day it is, the day the
 * conversation is about, today's appointment time (so "my 14:00" means it), tonight's
 * events by title and venue, and whether a trip is under way. Dates are Y-m-d, times
 * HH:MM, Europe/Berlin.
 */
final readonly class RouterContext
{
    /**
     * @param  list<array{id: int, title: string, venue: ?string}>  $tonight
     */
    public function __construct(
        public string $today,
        public string $date,
        public ?string $appointmentStart = null,
        public array $tonight = [],
        public bool $onTheWay = false,
    ) {}
}
