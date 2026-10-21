<?php

namespace App\Bureaucracy\People;

use Illuminate\Validation\ValidationException;

enum AccessScope: string
{
    case ViewPlan = 'view_plan';
    case ViewFacts = 'view_facts';
    case EditFacts = 'edit_facts';
    case ManageProcess = 'manage_process';
    case ManageEvidence = 'manage_evidence';
    case RequestAi = 'request_ai';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return list<string> */
    public static function validate(array $scopes): array
    {
        if ($scopes === [] || ! array_is_list($scopes) || count($scopes) > count(self::cases())) {
            throw ValidationException::withMessages(['scopes' => 'Choose the specific access to share.']);
        }
        foreach ($scopes as $scope) {
            if (! is_string($scope) || self::tryFrom($scope) === null) {
                throw ValidationException::withMessages(['scopes' => 'That access scope is not supported.']);
            }
        }

        return array_values(array_unique($scopes));
    }
}
