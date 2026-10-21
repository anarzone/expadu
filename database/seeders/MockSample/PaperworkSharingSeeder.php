<?php

namespace Database\Seeders\MockSample;

use App\Models\BureaucracyAccessGrant;
use App\Models\BureaucracyPerson;
use App\Support\MockAccounts;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Local only: the mock's sample sharing (bureaucracy-next, Share your plan:
 * "Alex · Your plan · Your documents · since 12 Sept 2026") on the compared
 * account's own Paperwork record, for tools/mock-compare. Alex is the
 * account's sibling <name>+alex@…, so no real person is involved. Seed
 * MockSample\PaperworkSeeder first; running this again puts the one grant
 * back as it was. PAPERWORK_PROCESSING=stopped also stops the record's
 * processing, as Your record's Stop processing does (EXP-180).
 *
 *   E2E_EMAIL=you@expadu.test php artisan db:seed --class='Database\Seeders\MockSample\PaperworkSharingSeeder'
 */
class PaperworkSharingSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('The mock sample is local only.');

            return;
        }

        $email = (string) env('E2E_EMAIL', 'e2e@expadu.test');
        $user = MockAccounts::ensure($email);
        $person = BureaucracyPerson::query()->where('account_user_id', $user->id)->first();
        if ($person === null) {
            $this->command?->error("{$email} has no Paperwork record: seed MockSample\\PaperworkSeeder first.");

            return;
        }

        $alex = MockAccounts::ensure(MockAccounts::sibling($email, 'alex'));
        $alex->forceFill(['name' => 'Alex'])->save();

        BureaucracyAccessGrant::query()->where('person_id', $person->id)->delete();
        BureaucracyAccessGrant::query()->create([
            'person_id' => $person->id,
            'grantee_user_id' => $alex->id,
            'grantor_user_id' => $user->id,
            'authority_basis' => 'adult_acceptance',
            'scopes' => ['view_plan', 'manage_evidence'],
            'notice_version' => (string) config('bureaucracy_family.sharing_notice_version'),
            'accepted_at' => CarbonImmutable::parse('2026-09-12 10:00', 'Europe/Berlin'),
            'expires_at' => CarbonImmutable::parse('2027-09-12 10:00', 'Europe/Berlin'),
        ]);

        $stopped = env('PAPERWORK_PROCESSING') === 'stopped';
        $person->forceFill(['processing_stopped_at' => $stopped ? now()->utc() : null])->save();

        $this->command?->info("{$email}'s plan is shared with Alex (Your plan, Your documents)".($stopped ? '; its processing is stopped.' : '.'));
    }
}
