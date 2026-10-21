<?php

namespace Database\Seeders\MockSample;

use App\Models\BureaucracyPerson;
use App\Models\WaitlistSignup;
use App\Support\MockAccounts;
use Illuminate\Database\Seeder;
use InvalidArgumentException;

/**
 * Local only: Paperwork's entry states for tools/mock-compare, each on its
 * own sibling of the compared account (<name>+<entry>@…), so the account's
 * own record is never touched. The mock shows them as personas
 * (prototype/dev/design/bureaucracy-next, Review → Outside Cologne, No
 * profile yet, Record unavailable):
 *
 *   setup        an account with no Paperwork record yet (setup required)
 *   outside      PaperworkSeeder's sample record, living outside the
 *                catalogue's cities (Hamburg): no reviewed guidance there
 *   unavailable  PaperworkSeeder's sample record, paused (record unavailable)
 *
 *   PAPERWORK_ENTRY=outside E2E_EMAIL=you@expadu.test php artisan db:seed --class='Database\Seeders\MockSample\PaperworkEntrySeeder'
 *
 * Outside, the sibling hasn't asked to be told yet ("Tell me when it's
 * covered"); PAPERWORK_WAITLIST=unconfirmed puts it on the city waitlist,
 * unconfirmed, as after the tap (no mail is sent).
 *
 * Running it again puts the sibling back in its state.
 */
class PaperworkEntrySeeder extends Seeder
{
    private const ENTRIES = ['setup', 'outside', 'unavailable'];

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('The mock sample is local only.');

            return;
        }

        $entry = (string) env('PAPERWORK_ENTRY', '');
        if (! in_array($entry, self::ENTRIES, true)) {
            throw new InvalidArgumentException('PAPERWORK_ENTRY is one of: '.implode(', ', self::ENTRIES).'.');
        }

        $email = MockAccounts::sibling((string) env('E2E_EMAIL', 'e2e@expadu.test'), $entry);
        $user = MockAccounts::ensure($email, fn (string $made) => $this->command?->info("Created {$made}."));

        if ($entry === 'setup') {
            if (BureaucracyPerson::query()->where('account_user_id', $user->id)->exists()) {
                $this->command?->warn("{$email} already has a Paperwork record, so it isn't a setup-required account any more: compare as another account.");

                return;
            }
            $this->command?->info("{$email} has no Paperwork record: setup required.");

            return;
        }

        $this->withAccount($email, fn () => $this->call(PaperworkSeeder::class));
        $user->forceFill(['city' => $entry === 'outside' ? 'Hamburg' : 'cologne'])->save();
        BureaucracyPerson::query()->where('account_user_id', $user->id)
            ->update(['record_status' => $entry === 'unavailable' ? 'paused' : 'active']);
        // Only the sibling's own row: before the tap, or after it, unconfirmed.
        WaitlistSignup::query()->where('email', mb_strtolower($email))->delete();
        if ($entry === 'outside' && env('PAPERWORK_WAITLIST') === 'unconfirmed') {
            WaitlistSignup::query()->create(['email' => mb_strtolower($email), 'city' => 'Hamburg', 'source' => 'paperwork']);
        }

        $this->command?->info("{$email}'s Paperwork record: {$entry}.");
    }

    /** Runs PaperworkSeeder, which reads E2E_EMAIL, for the sibling account. */
    private function withAccount(string $email, callable $seed): void
    {
        $before = getenv('E2E_EMAIL');
        putenv("E2E_EMAIL={$email}");
        $_ENV['E2E_EMAIL'] = $_SERVER['E2E_EMAIL'] = $email;
        try {
            $seed();
        } finally {
            $before === false ? putenv('E2E_EMAIL') : putenv("E2E_EMAIL={$before}");
            $_ENV['E2E_EMAIL'] = $_SERVER['E2E_EMAIL'] = $before === false ? null : $before;
        }
    }
}
