<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'name' => (string) $this->name,
            'email' => (string) $this->email,
            'email_verified' => $this->email_verified_at !== null,
            // Deleting asks for the password, or an emailed code for an account without one.
            'has_password' => $this->password !== null,
        ];
    }
}
