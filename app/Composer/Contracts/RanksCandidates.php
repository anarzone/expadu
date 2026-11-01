<?php

namespace App\Composer\Contracts;

use App\Composer\Candidate;
use App\Composer\Constraints;
use App\Privacy\ProcessingPermit;

interface RanksCandidates
{
    /**
     * @param  list<Candidate>  $candidates
     * @return array<string, float> Candidate id to preference weight.
     */
    public function rank(Constraints $constraints, array $candidates, array $preferences = [], ?ProcessingPermit $permit = null): array;
}
