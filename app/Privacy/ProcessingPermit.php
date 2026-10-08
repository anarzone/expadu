<?php

namespace App\Privacy;

/** Internal handle, never deserialised from a client's model attributes. */
final readonly class ProcessingPermit
{
    public function __construct(public string $id, public int $actorId) {}
}
