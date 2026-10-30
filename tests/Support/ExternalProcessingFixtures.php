<?php

namespace Tests\Support;

use App\Privacy\ProcessingPurpose;
use Illuminate\Support\Str;

final class ExternalProcessingFixtures
{
    /** A person's yes to a purpose's notice as it reads now, as the page sends it. */
    public static function acceptance(ProcessingPurpose $purpose, ?string $requestId = null): array
    {
        return [
            'consent' => true, 'request_id' => $requestId ?? (string) Str::uuid(),
            'notice_version' => $purpose->noticeVersion(),
            'provider_version' => $purpose->providerVersion(),
        ];
    }
}
