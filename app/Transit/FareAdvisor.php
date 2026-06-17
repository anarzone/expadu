<?php

namespace App\Transit;

use App\Transit\Dto\FareAdvice;
use App\Transit\Dto\Journey;

/**
 * Turns a planned {@see Journey} (+ the trip's origin/destination
 * municipality and whether the rider holds a Deutschlandticket) into
 * concrete Rheinlandtarif advice. Pure: every figure comes from
 * config/rheinlandtarif.php, no I/O — the caller resolves municipalities
 * (e.g. via MOTIS reverse-geocode) and passes them in.
 *
 * Accuracy honesty: within a single municipality the Preisstufe is exact
 * (Kurzstrecke / 1a / 1b). Across municipalities we have no official zone
 * polygons (VRS GTFS carries no fares), so 2-vs-3 is estimated by air-line
 * distance and flagged `estimated`, with eezy as the "never more than" net.
 */
class FareAdvisor
{
    public function advise(
        Journey $journey,
        ?string $fromMunicipality,
        ?string $toMunicipality,
        bool $hasDeutschlandticket = false,
    ): FareAdvice {
        /** @var array<string, mixed> $cfg */
        $cfg = config('rheinlandtarif');
        $dticket = (float) $cfg['deutschlandticket']['monthly_eur'];

        // A held Deutschlandticket covers all local + regional transit. Our
        // journeys are local (no IC/ICE), so it always covers them.
        if ($hasDeutschlandticket) {
            return new FareAdvice(
                coveredByDeutschlandticket: true,
                preisstufe: null,
                priceEur: null,
                estimated: false,
                eezyCapEur: null,
                deutschlandticketEur: $dticket,
                label: 'Covered by your Deutschlandticket',
                reason: 'Your Deutschlandticket covers all local and regional transit on this trip — no extra ticket needed.',
                howToBuy: [],
            );
        }

        [$level, $estimated] = $this->preisstufe($journey, $fromMunicipality, $toMunicipality, $cfg);
        $price = (float) ($cfg['single'][$level] ?? $cfg['single']['1b']);
        $breakeven = (int) ceil($dticket / max($price, 0.01));

        return new FareAdvice(
            coveredByDeutschlandticket: false,
            preisstufe: $level,
            priceEur: $price,
            estimated: $estimated,
            eezyCapEur: $price, // eezy's per-trip cap is the EinzelTicket
            deutschlandticketEur: $dticket,
            label: $this->label($level),
            reason: $this->reason($level, $estimated, $price, $dticket, $breakeven),
            howToBuy: $cfg['how_to_buy'],
        );
    }

    /**
     * @param  array<string, mixed>  $cfg
     * @return array{0: string, 1: bool} [preisstufe, estimated]
     */
    private function preisstufe(Journey $journey, ?string $from, ?string $to, array $cfg): array
    {
        // Kurzstrecke is a short-hop fare keyed on stop count, not zones, so
        // it can beat any level — check it first.
        if ($this->isKurzstrecke($journey, $cfg['kurzstrecke'])) {
            return ['K', false];
        }

        $sameMunicipality = $from !== null && $to !== null
            && $this->normalize($from) === $this->normalize($to);

        if ($sameMunicipality) {
            $cities = array_map(fn ($c) => $this->normalize($c), $cfg['city_municipalities']);

            return [in_array($this->normalize($from), $cities, true) ? '1b' : '1a', false];
        }

        // Cross-municipality (or unresolved): no zone data, so estimate by
        // air-line distance and flag it.
        $level = $this->airlineKm($journey) > (float) $cfg['cross_municipality_level3_km'] ? '3' : '2';

        return [$level, true];
    }

    /**
     * @param  array{max_stops: int, max_minutes: int, excluded_modes: list<string>}  $rule
     */
    private function isKurzstrecke(Journey $journey, array $rule): bool
    {
        $stops = 0;
        $minutes = 0;

        foreach ($journey->legs as $leg) {
            if (! $leg->isTransit()) {
                continue;
            }

            if (in_array($leg->mode, $rule['excluded_modes'], true)) {
                return false; // S-Bahn/RB/RE void the Kurzstrecke
            }

            $stops += $leg->stopsCount ?? 0;
            $minutes += $leg->durationMin;
        }

        return $stops > 0 && $stops <= $rule['max_stops'] && $minutes <= $rule['max_minutes'];
    }

    private function airlineKm(Journey $journey): float
    {
        if ($journey->legs === []) {
            return 0.0;
        }

        $legs = $journey->legs;
        $from = $legs[0]->from->point;
        $to = $legs[array_key_last($legs)]->to->point;

        $earthKm = 6371.0;
        $dLat = deg2rad($to->lat - $from->lat);
        $dLng = deg2rad($to->lng - $from->lng);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($from->lat)) * cos(deg2rad($to->lat)) * sin($dLng / 2) ** 2;

        return $earthKm * 2 * asin(min(1.0, sqrt($a)));
    }

    private function normalize(string $municipality): string
    {
        return mb_strtolower(trim($municipality));
    }

    private function label(string $level): string
    {
        return match ($level) {
            'K' => 'Kurzstrecke (short hop)',
            default => "Single ticket · level {$level}",
        };
    }

    private function reason(string $level, bool $estimated, float $price, float $dticket, int $breakeven): string
    {
        $eur = fn (float $v) => '€'.number_format($v, 2);

        if ($estimated) {
            return "Crosses tariff zones — about {$eur($price)} (level {$level}); confirm in the app. "
                ."eezy.nrw caps each trip at the single fare, and a Deutschlandticket ({$eur($dticket)}/mo) would cover it all.";
        }

        return "{$eur($price)} single, valid 90 min. eezy.nrw pay-as-you-go never charges more. "
            ."A Deutschlandticket ({$eur($dticket)}/mo) pays off from about {$breakeven} trips a month.";
    }
}
