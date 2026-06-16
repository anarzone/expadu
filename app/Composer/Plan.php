<?php

namespace App\Composer;

/**
 * An ordered day plan. Immutable — swapping returns a new Plan.
 */
final readonly class Plan
{
    /**
     * @param  list<PlanSlot>  $slots
     * @param  bool  $relaxed  the archetype couldn't be honoured against the
     *                         filtered pool, so the day was filled permissively
     *                         (drives the honest "I widened your picks" notice)
     */
    public function __construct(
        public Constraints $constraints,
        public array $slots,
        public bool $relaxed = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'constraints' => $this->constraints->toArray(),
            'slots' => array_map(fn (PlanSlot $slot) => $slot->toArray(), $this->slots),
        ];
    }
}
