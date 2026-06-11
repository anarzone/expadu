<?php

namespace App\Composer;

/**
 * An ordered day plan. Immutable — swapping returns a new Plan.
 */
final readonly class Plan
{
    /**
     * @param  list<PlanSlot>  $slots
     */
    public function __construct(
        public Constraints $constraints,
        public array $slots,
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
