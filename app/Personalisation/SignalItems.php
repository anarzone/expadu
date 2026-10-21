<?php

namespace App\Personalisation;

use App\Enums\EventCategory;
use Illuminate\Support\Facades\DB;

/**
 * What the aggregates key on, read from the catalogue when a signal is
 * recorded: the item's category, Veedel and price band. A place alias is
 * recorded as its canonical place. Unknown ids are left out.
 */
final class SignalItems
{
    /**
     * @param  list<int>  $spotIds
     * @param  list<int>  $eventIds
     * @return array<string, array{item_type: string, item_id: int, category: ?string, veedel: ?string, price_band: ?string}> keyed by the requested "type:id"
     */
    public function resolve(array $spotIds, array $eventIds): array
    {
        $items = [];

        if ($spotIds !== []) {
            $spots = DB::table('spots as s')
                ->leftJoin('spots as c', 'c.id', '=', 's.canonical_spot_id')
                ->whereIn('s.id', array_values(array_unique($spotIds)))
                ->selectRaw('s.id, coalesce(c.id, s.id) as canonical_id, coalesce(c.category, s.category) as category, coalesce(c.veedel, s.veedel) as veedel, coalesce(c.price_range, s.price_range) as price_range')
                ->get();
            foreach ($spots as $spot) {
                $items["spot:{$spot->id}"] = [
                    'item_type' => SignalItemType::Spot->value,
                    'item_id' => (int) $spot->canonical_id,
                    'category' => $spot->category ?: null,
                    'veedel' => $spot->veedel ?: null,
                    'price_band' => match ($spot->price_range) {
                        '€' => 'low',
                        '€€' => 'mid',
                        '€€€', '€€€€' => 'high',
                        default => null,
                    },
                ];
            }
        }

        if ($eventIds !== []) {
            $events = DB::table('events')
                ->leftJoin('venues', 'venues.id', '=', 'events.venue_id')
                ->whereIn('events.id', array_values(array_unique($eventIds)))
                ->get(['events.id', 'events.category', 'events.is_free', 'events.price', 'venues.veedel']);
            foreach ($events as $event) {
                $price = $event->price === null ? null : (float) $event->price;
                $items["event:{$event->id}"] = [
                    'item_type' => SignalItemType::Event->value,
                    'item_id' => (int) $event->id,
                    'category' => EventCategory::fromLegacy($event->category)->value,
                    'veedel' => $event->veedel ?: null,
                    'price_band' => match (true) {
                        (bool) $event->is_free => 'free',
                        $price === null => null,
                        $price < 15 => 'low',
                        $price < 40 => 'mid',
                        default => 'high',
                    },
                ];
            }
        }

        return $items;
    }
}
