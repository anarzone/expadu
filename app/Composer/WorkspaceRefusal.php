<?php

namespace App\Composer;

use RuntimeException;

/**
 * A change that cannot go into the plan (a clash, a time outside listed hours,
 * a fixed time). The plan stays as it was and the person is told why.
 */
final class WorkspaceRefusal extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
