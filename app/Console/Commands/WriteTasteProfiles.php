<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Personalisation\TasteProfileWriter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Nightly, after signals:aggregate: rewrites the taste profile of each
 * opted-in person whose facts changed. Prints counts only, never a person's
 * profile or id.
 */
#[Signature('taste:profiles {--user= : Refresh one account (by id) only}')]
#[Description('Write taste profiles (Haiku) for opted-in people whose taste facts changed')]
class WriteTasteProfiles extends Command
{
    public function handle(TasteProfileWriter $writer): int
    {
        if (! $writer->isConfigured()) {
            $this->info('Taste profiles are off or there is no ANTHROPIC_API_KEY; nothing written.');

            return self::SUCCESS;
        }

        $users = User::query()
            ->with('userSetting')
            ->whereHas('userSetting', fn ($query) => $query->where('settings->personalisation', true));
        if (filled($this->option('user'))) {
            $users->whereKey((int) $this->option('user'));
        }

        $outcomes = [];
        $users->chunkById(200, function ($chunk) use ($writer, &$outcomes): bool {
            foreach ($chunk as $user) {
                $outcome = $writer->refresh($user);
                $outcomes[$outcome] = ($outcomes[$outcome] ?? 0) + 1;
                if ($outcome === 'capped') {
                    $this->warn('Daily taste profile limit reached.');

                    return false;
                }
            }

            return true;
        });

        ksort($outcomes);
        $this->info('Taste profiles: '.($outcomes === [] ? 'nobody to refresh' : collect($outcomes)->map(fn ($n, $outcome) => "{$outcome} {$n}")->implode(', ')).'.');

        return self::SUCCESS;
    }
}
