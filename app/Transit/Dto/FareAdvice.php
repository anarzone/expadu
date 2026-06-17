<?php

namespace App\Transit\Dto;

/**
 * Journey-aware Rheinlandtarif advice for one trip: which ticket to buy,
 * what it costs, and how. `estimated` is set when the Preisstufe had to be
 * guessed (cross-municipality, where we lack official zone polygons) — the
 * UI should hedge ("≈") and lean on the eezy "never more than" framing.
 */
final readonly class FareAdvice
{
    /**
     * @param  'K'|'1a'|'1b'|'2'|'3'|null  $preisstufe
     * @param  list<array{label: string, url: string}>  $howToBuy
     */
    public function __construct(
        public bool $coveredByDeutschlandticket,
        public ?string $preisstufe,
        public ?float $priceEur,
        public bool $estimated,
        public ?float $eezyCapEur,
        public float $deutschlandticketEur,
        public string $label,
        public string $reason,
        public array $howToBuy,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'covered_by_deutschlandticket' => $this->coveredByDeutschlandticket,
            'preisstufe' => $this->preisstufe,
            'price_eur' => $this->priceEur,
            'estimated' => $this->estimated,
            'eezy_cap_eur' => $this->eezyCapEur,
            'deutschlandticket_eur' => $this->deutschlandticketEur,
            'label' => $this->label,
            'reason' => $this->reason,
            'how_to_buy' => $this->howToBuy,
        ];
    }
}
