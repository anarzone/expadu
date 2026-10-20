<?php

namespace App\Console\Commands;

use App\Media\ReviewMediaMatch;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\Console\Output\OutputInterface;

#[Signature('media:review-match {attachment : Media attachment ID} {decision : accepted or rejected}
    {--method=manual_review : Stable match method identifier}
    {--apply : Save the reviewed decision; default is a read-only preview}
    {--fingerprint= : Fingerprint from the reviewed preview}
    {--evidence= : Documented subject-match evidence}
    {--actor= : Reviewer or operator identifier}')]
#[Description('Preview or record an evidence-reviewed media-to-place match decision')]
class ReviewMediaMatchCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ReviewMediaMatch $review): int
    {
        $attachment = (string) $this->argument('attachment');
        if (! ctype_digit($attachment) || (int) $attachment < 1) {
            $this->error('Media attachment ID must be a positive integer.');

            return self::FAILURE;
        }

        $decision = (string) $this->argument('decision');
        $method = (string) $this->option('method');
        try {
            if ($this->option('apply')) {
                $review->apply(
                    (int) $attachment,
                    $decision,
                    $method,
                    (string) $this->option('fingerprint'),
                    (string) $this->option('evidence'),
                    (string) $this->option('actor'),
                );
            }
            $preview = $review->preview((int) $attachment, $decision, $method);
        } catch (DomainException|ModelNotFoundException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->output->writeln(json_encode(
            ['applied' => (bool) $this->option('apply'), ...$preview],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ), OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
