<?php

namespace App\Transit\Dto;

final readonly class GeoPoint
{
    public function __construct(
        public float $lat,
        public float $lng,
    ) {}
}
