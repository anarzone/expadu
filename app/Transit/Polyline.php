<?php

namespace App\Transit;

/**
 * Encoded polylines (the Google algorithm) at one precision for every map:
 * MOTIS's 7. Our MOTIS answers at 7, Transitous's v3 at 6 (its legGeometry
 * says which); decoding a 6 at 7 put every line in the Gulf of Guinea and
 * the trip map zoomed out to two continents.
 */
final class Polyline
{
    public const PRECISION = 7;

    /** The same line at PRECISION; as given when it already is (or can't be read). */
    public static function atPrecision(string $points, int $precision): string
    {
        if ($precision === self::PRECISION || $precision < 1 || $precision > 9) {
            return $points;
        }

        $pairs = self::decode($points, $precision);

        return $pairs === null ? $points : self::encode($pairs, self::PRECISION);
    }

    /**
     * @return list<array{0: float, 1: float}>|null [lat, lng] pairs; null when malformed
     */
    public static function decode(string $points, int $precision): ?array
    {
        $factor = 10 ** $precision;
        $pairs = [];
        $index = 0;
        $length = strlen($points);
        $lat = 0;
        $lng = 0;

        while ($index < $length) {
            foreach ([0, 1] as $axis) {
                $shift = 0;
                $result = 0;
                do {
                    if ($index >= $length) {
                        return null;
                    }
                    $byte = ord($points[$index++]) - 63;
                    $result |= ($byte & 0x1F) << $shift;
                    $shift += 5;
                } while ($byte >= 0x20);
                $delta = ($result & 1) ? ~($result >> 1) : ($result >> 1);
                $axis === 0 ? $lat += $delta : $lng += $delta;
            }
            $pairs[] = [$lat / $factor, $lng / $factor];
        }

        return $pairs;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $pairs  [lat, lng]
     */
    public static function encode(array $pairs, int $precision): string
    {
        $factor = 10 ** $precision;
        $out = '';
        $lastLat = 0;
        $lastLng = 0;

        foreach ($pairs as [$lat, $lng]) {
            $ilat = (int) round($lat * $factor);
            $ilng = (int) round($lng * $factor);
            $out .= self::number($ilat - $lastLat).self::number($ilng - $lastLng);
            $lastLat = $ilat;
            $lastLng = $ilng;
        }

        return $out;
    }

    private static function number(int $value): string
    {
        $value = $value < 0 ? ~($value << 1) : $value << 1;
        $out = '';
        while ($value >= 0x20) {
            $out .= chr((0x20 | ($value & 0x1F)) + 63);
            $value >>= 5;
        }

        return $out.chr($value + 63);
    }
}
