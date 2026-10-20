<?php

namespace App\Privacy;

use Closure;

final class ProcessingAcceptanceRules
{
    public static function rules(): array
    {
        return [
            'processing' => ['sometimes', 'array:consent,request_id,notice_version,provider_version'],
            'processing.consent' => ['required_with:processing', function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== true) {
                    $fail('Explicit permission is required for this request.');
                }
            }],
            'processing.request_id' => ['required_with:processing', 'uuid'],
            'processing.notice_version' => ['required_with:processing', 'string', 'max:100'],
            'processing.provider_version' => ['required_with:processing', 'string', 'size:64'],
        ];
    }
}
