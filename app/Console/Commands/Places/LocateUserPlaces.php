<?php

namespace App\Console\Commands\Places;

use App\Models\UserPlace;
use App\Places\AddressLocator;
use Illuminate\Console\Command;

/**
 * Once, after EXP-190: people's places saved with a typed address and no
 * point (a Home typed without picking a suggestion) are looked up the way a
 * save now does. Whatever isn't found keeps no point; the places page tells
 * its owner. Prints ids and counts only, never an address.
 */
class LocateUserPlaces extends Command
{
    protected $signature = 'places:locate-addresses {--dry-run : Report what would be located without saving}';

    protected $description = 'Give places saved with an address but no point the point of that address, when it is found exactly';

    public function handle(AddressLocator $locator): int
    {
        $located = 0;
        $missing = [];

        UserPlace::query()
            ->where(fn ($query) => $query->whereNull('lat')->orWhereNull('lng'))
            ->whereNotNull('address')
            ->where('address', '!=', '')
            ->orderBy('id')
            ->each(function (UserPlace $place) use ($locator, &$located, &$missing): void {
                $point = $locator->locate($place->address);
                // Photon's fair use: about one lookup a second.
                usleep(1_000_000);

                if ($point === null) {
                    $missing[] = $place->id;

                    return;
                }

                $located++;

                if (! $this->option('dry-run')) {
                    $place->update([...$point, 'located_from_address' => true]);
                }
            });

        $this->info(($this->option('dry-run') ? 'Would locate ' : 'Located ').$located.' place(s).');

        if ($missing !== []) {
            $this->warn('Not found, left without a point: '.implode(', ', $missing));
        }

        return self::SUCCESS;
    }
}
