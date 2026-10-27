<?php

namespace App\Composer\Actions;

use App\Composer\Answers\Destinations;
use App\Models\BureaucracyPerson;
use App\Models\User;
use App\Models\UserPlace;
use App\Places\AddressLocator;
use App\Places\SetHomeOrWork;
use Carbon\CarbonImmutable;

/**
 * Sets Home or Work to an address or a place the person names, as Travel's address line
 * does (SetHomeOrWork). A typed address is looked up, never guessed; its point and the
 * address itself stay out of what the AI reads.
 */
final class MyPlaceAction implements ComposerAction
{
    public function __construct(private AddressLocator $addresses, private Destinations $destinations) {}

    public function prepare(User $user, string $action, array $input, CarbonImmutable $now): array
    {
        $which = ($input['which'] ?? 'home') === 'work' ? 'work' : 'home';
        $said = trim((string) ($input['place'] ?? ''));
        if (mb_strlen($said) < 3) {
            throw new ActionRefused('Say the address or the place.');
        }
        $place = $this->locate($said, is_string($input['area'] ?? null) ? $input['area'] : null);
        $label = $which === 'home' ? 'Home' : 'Work';
        $current = $user->places()->where('category', $which)->first();
        $previous = $current?->lat === null ? null : ['name' => $current->name, 'address' => $current->address, 'lat' => $current->lat, 'lng' => $current->lng,
            'located_from_address' => $current->located_from_address];
        if ($previous !== null && mb_strtolower($previous['name']) === mb_strtolower($place['name'])) {
            throw new ActionRefused("{$label} is already {$place['name']}.");
        }

        return ['which' => $which, 'place' => $place, 'previous' => $previous,
            'propose' => "Set {$label} to {$place['name']}?", 'sub' => self::sub($label, $previous),
            // The address stays out of what the AI is told; the card says it.
            'tell' => "Set {$label} to the place they named?"];
    }

    public function run(User $user, string $action, array $prepared, CarbonImmutable $now): array
    {
        app(SetHomeOrWork::class)->execute($user, $prepared['which'], $prepared['place']);
        $label = $prepared['which'] === 'home' ? 'Home' : 'Work';
        // Paperwork dates the address registration from the move-in day: asked for, when they have a record.
        $paperwork = $prepared['which'] === 'home' && BureaucracyPerson::query()->where('account_user_id', $user->id)
            ->where('record_status', 'active')->whereNull('processing_stopped_at')->exists();

        return array_filter(['title' => "{$label} is now {$prepared['place']['name']}", 'sub' => $prepared['sub'],
            'link' => ['label' => 'See your places →', 'href' => '/travel'],
            'ask' => $paperwork ? ['text' => 'When did you move in? Paperwork dates your address registration from it.',
                'options' => [['label' => 'Today', 'prompt' => 'I moved in today'], ['label' => 'Another day…', 'draft' => 'I moved in on ']]] : null,
            // The address stays out of what the AI is told; the card says it.
            'said' => "{$label} is now set to the place they named.",
            'undo' => ['which' => $prepared['which'], 'previous' => $prepared['previous']]], fn ($v) => $v !== null);
    }

    public function undo(User $user, string $action, array $undo, CarbonImmutable $now): array
    {
        $label = $undo['which'] === 'home' ? 'Home' : 'Work';
        if ($undo['previous'] === null) {
            UserPlace::query()->where('user_id', $user->id)->where('category', $undo['which'])->delete();

            return ['title' => "Undone. {$label} is empty again."];
        }
        app(SetHomeOrWork::class)->execute($user, $undo['which'], $undo['previous']);

        return ['title' => "Undone. {$label} is {$undo['previous']['name']} again."];
    }

    /**
     * An address, looked up; else a place by its name (a stop, a square, a building).
     *
     * @return array{name: string, address: ?string, lat: float, lng: float, located_from_address: bool}
     *
     * @throws ActionRefused
     */
    private function locate(string $said, ?string $area): array
    {
        if (preg_match('/\d/', $said) === 1) {
            $point = $this->addresses->locate($said);
            if ($point === null) {
                throw new ActionRefused("“{$said}” wasn’t found as an address in Cologne. Travel can look it up.");
            }

            return ['name' => $said, 'address' => $said, 'lat' => $point['lat'], 'lng' => $point['lng'], 'located_from_address' => true];
        }
        $found = $this->destinations->find($said, $area, null);
        if ($found === []) {
            throw new ActionRefused("“{$said}” wasn’t found. Say the address, with its house number.");
        }
        if (count($found) > 1) {
            throw new ActionRefused("More than one place is called “{$said}”: in ".implode(', ', array_filter(array_column($found, 'area'))).'. Ask which.');
        }

        return ['name' => $found[0]['name'], 'address' => null, 'lat' => $found[0]['point']->lat, 'lng' => $found[0]['point']->lng, 'located_from_address' => false];
    }

    private static function sub(string $label, ?array $previous): string
    {
        return 'Travel and My day plan from it. '.($previous === null ? "{$label} was empty before." : "It was {$previous['name']} before.");
    }
}
