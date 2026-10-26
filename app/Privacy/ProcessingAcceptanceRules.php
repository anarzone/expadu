<?php

namespace App\Privacy;

use Closure;

final class ProcessingAcceptanceRules
{
    /** @param  string  $key  the request field holding the acceptance ("processing"; Composer's Paperwork one is "paperwork_processing") */
    public static function rules(string $key = 'processing'): array
    {
        return [
            $key => ['sometimes', 'array:consent,request_id,notice_version,provider_version'],
            "{$key}.consent" => ["required_with:{$key}", function (string $attribute, mixed $value, Closure $fail): void {
                if ($value !== true) {
                    $fail('Explicit permission is required for this request.');
                }
            }],
            "{$key}.request_id" => ["required_with:{$key}", 'uuid'],
            "{$key}.notice_version" => ["required_with:{$key}", 'string', 'max:100'],
            "{$key}.provider_version" => ["required_with:{$key}", 'string', 'size:64'],
        ];
    }
}
