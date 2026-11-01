<?php

namespace App\Bureaucracy\Evidence;

/** A command receipt is not the latest editable document snapshot. */
final readonly class EvidenceWriteReceipt
{
    public function __construct(public string $id, public int $version, public string $status) {}
}
