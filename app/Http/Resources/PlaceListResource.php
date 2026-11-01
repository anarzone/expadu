<?php

namespace App\Http\Resources;

/**
 * A place as a list card carries it. The card and the detail sheet opened
 * from it read only identity, the entrance, access, fee, hours and the
 * description from the evidence record; the practical facts already arrive
 * as feature_chips/facts, and the full record (contact, practical, parsed
 * hours…) comes from /api/places/{id}. This was ~90% of each list item.
 */
class PlaceListResource extends PlaceResource
{
    /**
     * @param  array<string, mixed>  $placeFacts
     * @return array<string, mixed>
     */
    protected function placeFacts(array $placeFacts): array
    {
        $hours = $placeFacts['hours'];
        unset($hours['parsed']);

        return [
            'identity' => $placeFacts['identity'],
            'name_kind' => $placeFacts['name_kind'],
            'location' => ['entrance_point' => $placeFacts['location']['entrance_point']],
            'access' => $placeFacts['access'],
            'fee' => $placeFacts['fee'],
            'hours' => $hours,
            'description' => $placeFacts['description'],
        ];
    }
}
