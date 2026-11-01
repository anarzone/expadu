<?php

namespace App\Transit\Dto;

use Carbon\CarbonImmutable;

final readonly class Leg
{
    /**
     * @param  array<int, array{name: string, arrive_at: string, arrive_time: string, lat: ?float, lng: ?float}>|null  $intermediateStops
     *                                                                                                                                     The stations ridden through (excluding board + get-off), in order —
     *                                                                                                                                     powers the timeline and live GPS map-matching.
     */
    public function __construct(
        public string $mode, // walk | bus | tram | subway | rail | ferry
        public Place $from,
        public Place $to,
        public CarbonImmutable $departAt,
        public CarbonImmutable $arriveAt,
        public int $durationMin,
        public ?string $lineName = null,
        public ?string $headsign = null,
        public ?string $polyline = null,
        public ?int $stopsCount = null, // stops ridden until get-off (transit legs)
        public ?array $intermediateStops = null,
        public ?string $lineColor = null, // "#RRGGBB" when the feed carries one
        public ?CarbonImmutable $scheduledDepartAt = null, // timetable time; departAt is live-adjusted when $realtime
        public ?CarbonImmutable $scheduledArriveAt = null,
        public ?int $delayMin = null, // departure delay in whole minutes (negative = early); null without live data
        public bool $realtime = false, // departAt/arriveAt reflect a live feed, not just the timetable
        public bool $cancelled = false,
        public ?string $tripId = null, // the feed's trip id, to re-match this ride across plans
    ) {}

    public function isTransit(): bool
    {
        return $this->mode !== 'walk' && $this->mode !== 'bike';
    }

    /**
     * The mode a rider knows it by. Routers give S-Bahn (and sometimes RE or
     * RB) legs the Stadtbahn's 'subway', so Travel said "Tram S12": a line
     * named S…, RE…, RB…, IC…, ICE… or EC… is a train. The Stadtbahn's own
     * numbered lines stay as they are.
     */
    public function riderMode(): string
    {
        $line = strtoupper(trim((string) $this->lineName));

        return in_array($this->mode, ['subway', 'tram'], true) && preg_match('/^(S|RE|RB|IC|ICE|EC)\s?\d/', $line) === 1
            ? 'rail'
            : $this->mode;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->riderMode(),
            'line' => $this->lineName,
            'headsign' => $this->headsign,
            'from' => $this->from->toArray(),
            'to' => $this->to->toArray(),
            'depart_at' => $this->departAt->toIso8601String(),
            'arrive_at' => $this->arriveAt->toIso8601String(),
            'depart_time' => $this->departAt->format('H:i'),
            'arrive_time' => $this->arriveAt->format('H:i'),
            'duration_min' => $this->durationMin,
            'polyline' => $this->polyline,
            'stops' => $this->stopsCount,
            'intermediate_stops' => $this->intermediateStops,
            'color' => $this->lineColor,
            'scheduled_depart_at' => $this->scheduledDepartAt?->toIso8601String(),
            'scheduled_arrive_at' => $this->scheduledArriveAt?->toIso8601String(),
            'delay_min' => $this->delayMin,
            'realtime' => $this->realtime,
            'cancelled' => $this->cancelled,
            'trip_id' => $this->tripId,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            mode: (string) $data['mode'],
            from: Place::fromArray($data['from']),
            to: Place::fromArray($data['to']),
            departAt: CarbonImmutable::parse($data['depart_at']),
            arriveAt: CarbonImmutable::parse($data['arrive_at']),
            durationMin: (int) $data['duration_min'],
            lineName: $data['line'] ?? null,
            headsign: $data['headsign'] ?? null,
            polyline: $data['polyline'] ?? null,
            stopsCount: isset($data['stops']) ? (int) $data['stops'] : null,
            intermediateStops: $data['intermediate_stops'] ?? null,
            lineColor: $data['color'] ?? null,
            // Cached arrays from before the realtime fields lack these keys.
            scheduledDepartAt: isset($data['scheduled_depart_at']) ? CarbonImmutable::parse($data['scheduled_depart_at']) : null,
            scheduledArriveAt: isset($data['scheduled_arrive_at']) ? CarbonImmutable::parse($data['scheduled_arrive_at']) : null,
            delayMin: isset($data['delay_min']) ? (int) $data['delay_min'] : null,
            realtime: (bool) ($data['realtime'] ?? false),
            cancelled: (bool) ($data['cancelled'] ?? false),
            tripId: $data['trip_id'] ?? null,
        );
    }
}
