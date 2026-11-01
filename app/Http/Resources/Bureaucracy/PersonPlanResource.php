<?php

namespace App\Http\Resources\Bureaucracy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Only PlanReadModel's explicit scalar contract enters this resource, never Eloquent attributes. */
class PersonPlanResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
