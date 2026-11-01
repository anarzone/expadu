<?php

namespace App\Console\Commands\Bureaucracy;

use App\Bureaucracy\Catalogue\CatalogueReleaseStore;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

#[Signature('bureaucracy:recover-catalogue {--suspend : Withdraw the active catalogue without deleting data} {--release= : Restore a specific immutable release ID} {--expected-current= : Exact active hash, or none when restoring from suspension}')]
#[Description('Explicit catalogue recovery with concurrency checks and no legacy-engine fallback')]
class RecoverCatalogueCommand extends Command
{
    public function handle(CatalogueReleaseStore $store): int
    {
        $suspend = $this->option('suspend');
        $release = $this->option('release');
        $expected = $this->option('expected-current');
        if ($suspend === ($release !== null)
            || ! is_string($expected)
            || (! preg_match('/\A[a-f0-9]{64}\z/', $expected) && ($suspend || $expected !== 'none'))
            || ($release !== null && (! is_string($release) || ! preg_match('/\A[1-9][0-9]{0,17}\z/', $release)))) {
            $this->error('Choose --suspend or --release=ID and supply the exact --expected-current hash (none is allowed only for restoration).');

            return self::FAILURE;
        }
        try {
            if ($suspend) {
                $store->suspend($expected);
            } else {
                $store->activate((int) $release, $expected === 'none' ? null : $expected);
            }
        } catch (DomainException|ConflictHttpException|ModelNotFoundException $exception) {
            $this->error($exception instanceof ModelNotFoundException ? 'The requested release does not exist.' : $exception->getMessage());

            return self::FAILURE;
        }
        $this->info($suspend ? 'Catalogue suspended. Facts and process history were preserved.'
            : 'Catalogue pointer restored. Current source-withdrawal checks still apply; this does not approve content.');

        return self::SUCCESS;
    }
}
