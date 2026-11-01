<?php

namespace App\Bureaucracy\Facts;

use App\Models\BureaucracyCaseFact;
use App\Models\BureaucracyPerson;
use App\Models\User;

final class CorrectFact
{
    public function __construct(private WriteFactAssertion $writer) {}

    public function execute(User $actor, BureaucracyPerson $person, int $priorFactId, mixed $value, int $expectedRevision, string $answerState = 'value', FactInputMethod $method = FactInputMethod::Structured): BureaucracyCaseFact
    {
        return $this->writer->write($actor, $person, null, $value, null, $expectedRevision, $answerState, $priorFactId, $method);
    }
}
