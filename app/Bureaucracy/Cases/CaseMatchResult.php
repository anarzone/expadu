<?php

namespace App\Bureaucracy\Cases;

use App\Enums\BureaucracyCoverageState;

final readonly class CaseMatchResult
{
    /**
     * @param  list<string>  $matchedRuleKeys
     * @param  array<string, string>  $ruleVersions
     * @param  list<string>  $missingFactKeys
     * @param  list<array{0: string, 1: string}>  $conflictPairs
     * @param  list<string>  $safeRuleKeys
     * @param  list<string>  $universalRuleKeys
     * @param  list<string>  $unknownRuleKeys
     * @param  array<string, list<string>>  $missingFactsByRule
     */
    public function __construct(
        public BureaucracyCoverageState $coverageState,
        public array $matchedRuleKeys,
        public array $ruleVersions,
        public array $missingFactKeys,
        public array $conflictPairs,
        public array $safeRuleKeys,
        public array $universalRuleKeys = [],
        public array $unknownRuleKeys = [],
        public array $missingFactsByRule = [],
    ) {}
}
