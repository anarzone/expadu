<?php

namespace App\Console\Commands\Bureaucracy;

use App\Bureaucracy\Migration\BackfillPersonDossiers;
use App\Bureaucracy\Migration\LegacyMigrationPlanner;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

#[Signature('bureaucracy:migrate-dossiers {--dry-run : Read-only rehearsal (the default)} {--apply : Explicitly attach account-holder dossiers; no progress transfer} {--after=0 : Resume after this account ID} {--limit=100 : Maximum accounts in this batch (1-500)} {--all : Keep going batch by batch until every account is processed}')]
#[Description('Rehearse or attach existing account holders without rewriting answers or transferring saved progress')]
class MigrateDossiersCommand extends Command
{
    public function handle(LegacyMigrationPlanner $planner, BackfillPersonDossiers $backfill): int
    {
        $after = filter_var($this->option('after'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        if ($after === false || $limit === false || ($this->option('apply') && $this->option('dry-run'))) {
            $this->error('Choose rehearsal or apply, a non-negative cursor, and a batch size from 1 to 500.');

            return self::FAILURE;
        }
        $summary = ['mode' => $this->option('apply') ? 'apply' : 'dry_run', 'accounts' => 0, 'statuses' => [], 'next_cursor' => $after];
        do {
            $batch = User::query()->where('id', '>', $summary['next_cursor'])->orderBy('id')->limit($limit)->get();
            foreach ($batch as $user) {
                $review = $planner->for($user);
                if ($this->option('apply') && $review['status'] === 'identity_mismatch') {
                    $this->line(json_encode($summary, JSON_THROW_ON_ERROR));
                    $this->error('An account identity needs review. No records in that account were changed. Resume from the reported cursor after resolving it.');

                    return self::FAILURE;
                }
                if ($this->option('apply') && in_array($review['status'], ['ready', 'already_linked'], true)) {
                    try {
                        $review = $backfill->execute($user, $review['fingerprint']);
                    } catch (ConflictHttpException) {
                        $this->line(json_encode($summary, JSON_THROW_ON_ERROR));
                        $this->error('An account changed during this batch. Rehearse again and resume from the reported cursor.');

                        return self::FAILURE;
                    }
                }
                $summary['accounts']++;
                $summary['statuses'][$review['status']] = ($summary['statuses'][$review['status']] ?? 0) + 1;
                $summary['next_cursor'] = $user->id;
            }
        } while ($this->option('all') && $batch->count() === $limit);
        $this->line(json_encode($summary, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
