<?php

namespace App\Console\Commands;

use App\Places\ReconcilePlace;
use DomainException;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

class ReconcilePlaceIdentity extends Command
{
    protected $signature = 'places:reconcile-identity {alias : Legacy place ID} {canonical : Canonical OSM place ID}
        {--fingerprint= : Fingerprint from the reviewed preview}
        {--evidence= : Documented evidence confirming one physical place}
        {--apply : Apply the reviewed mapping; otherwise only preview}';

    protected $description = 'Preview or explicitly reconcile a reviewed legacy place identity while retaining old references';

    public function handle(ReconcilePlace $reconcile): int
    {
        foreach (['alias', 'canonical'] as $argument) {
            if (! ctype_digit((string) $this->argument($argument)) || (int) $this->argument($argument) < 1) {
                $this->error('Place IDs must be positive integers.');

                return self::FAILURE;
            }
        }
        $alias = (int) $this->argument('alias');
        $canonical = (int) $this->argument('canonical');
        try {
            if ($this->option('apply')) {
                $reconcile->apply($alias, $canonical, (string) $this->option('fingerprint'), (string) $this->option('evidence'));
            }
            $preview = $reconcile->preview($alias, $canonical);
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->output->writeln(json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
