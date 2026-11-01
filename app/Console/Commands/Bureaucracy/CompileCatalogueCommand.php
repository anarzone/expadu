<?php

namespace App\Console\Commands\Bureaucracy;

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueHash;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Models\BureaucracyCatalogueRelease;
use App\Models\Task;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

#[Signature('bureaucracy:compile-catalogue {--dry-run : Validate without writing} {--activate : Explicitly activate the staged release} {--expected-current= : Expected current hash, or none for first activation} {--deploy : Stage and activate for an automated deploy; never re-activates a deliberately suspended catalogue}')]
#[Description('Validate and stage an immutable reviewed process catalogue without deleting history')]
class CompileCatalogueCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CatalogueCompiler $compiler, CatalogueReleaseStore $store): int
    {
        if ($this->option('deploy') && ($this->option('activate') || $this->option('dry-run'))) {
            $this->error('--deploy cannot be combined with --activate or --dry-run.');

            return self::FAILURE;
        }
        if ($this->option('activate') && ($this->option('dry-run') || ! is_string($this->option('expected-current')))) {
            $this->error('Activation requires --expected-current=none or the exact active hash, and cannot be combined with --dry-run.');

            return self::FAILURE;
        }
        try {
            DB::transaction(function () use ($compiler, $store): void {
                DB::select('select pg_advisory_xact_lock(?)', [73408201]);
                $artifact = $compiler->compile(Task::query()->whereNotNull('key')->orderBy('key')->get()->all());
                $this->info('Validated '.count($artifact['inventory']).' source records; '.count($artifact['definitions']).' process definitions. Hash: '.CatalogueHash::of($artifact));
                if ($this->option('dry-run')) {
                    return;
                }
                $release = $store->stage($artifact);
                $this->info('Staged release '.$release->id.'. Staging a release does not activate it.');
                if ($this->option('activate')) {
                    $expected = $this->option('expected-current');
                    $store->activate($release->id, $expected === 'none' ? null : $expected);
                    $this->info('Activated the explicitly requested catalogue release.');
                } elseif ($this->option('deploy')) {
                    $pointer = DB::table('bureaucracy_catalogue_pointers')->where('name', 'active')->lockForUpdate()->first();
                    // A null pointer after version 1 means someone suspended the
                    // catalogue on purpose; a deploy must not undo that kill switch.
                    if ($pointer->release_id === null && $pointer->version > 1) {
                        $this->warn('The catalogue is suspended. Staged only; restore it with bureaucracy:recover-catalogue.');

                        return;
                    }
                    $current = $pointer->release_id === null ? null
                        : BureaucracyCatalogueRelease::query()->whereKey($pointer->release_id)->value('content_hash');
                    $store->activate($release->id, $current);
                    $this->info('Activated release '.$release->id.' for this deploy.');
                }
            });
        } catch (DomainException|ConflictHttpException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
