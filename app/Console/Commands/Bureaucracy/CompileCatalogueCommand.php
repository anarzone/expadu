<?php

namespace App\Console\Commands\Bureaucracy;

use App\Bureaucracy\Catalogue\CatalogueCompiler;
use App\Bureaucracy\Catalogue\CatalogueHash;
use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use App\Models\Task;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

#[Signature('bureaucracy:compile-catalogue {--dry-run : Validate without writing} {--activate : Explicitly activate the staged release} {--expected-current= : Expected current hash, or none for first activation}')]
#[Description('Validate and stage an immutable reviewed process catalogue without deleting history')]
class CompileCatalogueCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CatalogueCompiler $compiler, CatalogueReleaseStore $store): int
    {
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
                }
            });
        } catch (DomainException|ConflictHttpException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
