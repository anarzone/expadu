<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Either a token, or the challenge for two-step sign-in. One shape, so the generated
 * client has one type to read.
 *
 * @property-read array{user?: User, token?: string, expires_at?: string, challenge?: string} $resource
 */
class SignInResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'two_factor' => isset($this->resource['challenge']),
            'challenge' => $this->resource['challenge'] ?? null,
            'token' => $this->resource['token'] ?? null,
            'expires_at' => $this->resource['expires_at'] ?? null,
            'user' => isset($this->resource['user']) ? new UserResource($this->resource['user']) : null,
        ];
    }
}
