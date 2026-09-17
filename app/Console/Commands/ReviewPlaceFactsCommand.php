<?php

namespace App\Console\Commands;

use App\Places\ReviewPlaceFacts;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use JsonException;
use Symfony\Component\Console\Output\OutputInterface;

#[Signature('places:review-facts {spot : Place ID}
    {--changes= : JSON file containing reviewed field changes}
    {--apply : Save the reviewed changes; default is a read-only preview}
    {--fingerprint= : Fingerprint from the reviewed preview}
    {--evidence= : Source URL or evidence note}
    {--actor= : Reviewer or operator identifier}')]
#[Description('Preview or apply source-backed place fact corrections')]
class ReviewPlaceFactsCommand extends Command
{
    public function handle(ReviewPlaceFacts $review): int
    {
        $spot = (string) $this->argument('spot');
        if (! ctype_digit($spot) || (int) $spot < 1) {
            $this->error('Place ID must be a positive integer.');

            return self::FAILURE;
        }

        try {
            $changes = $this->readChanges((string) $this->option('changes'));
            if ($this->option('apply')) {
                $review->apply(
                    (int) $spot,
                    $changes,
                    (string) $this->option('fingerprint'),
                    (string) $this->option('evidence'),
                    (string) $this->option('actor'),
                );
            }
            $preview = $review->preview((int) $spot, $changes);
        } catch (DomainException|JsonException|ModelNotFoundException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->output->writeln(json_encode(
            ['applied' => (bool) $this->option('apply'), ...$preview],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function readChanges(string $option): array
    {
        $option = trim($option);
        if ($option === '') {
            throw new DomainException('Pass a JSON changes file with --changes.');
        }
        $path = str_starts_with($option, DIRECTORY_SEPARATOR) ? $option : base_path($option);
        if (! is_file($path) || ! is_readable($path)) {
            throw new DomainException('Changes file is not readable.');
        }
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new DomainException('Changes file must contain one keyed JSON object.');
        }

        return $decoded;
    }
}
