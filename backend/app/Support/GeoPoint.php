<?php

namespace App\Support;

class GeoPoint
{
    public static function make(float $lng, float $lat): array
    {
        return [
            'type' => 'Point',
            'coordinates' => [$lng, $lat],
        ];
    }

    public static function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return round($earth * (2 * atan2(sqrt($a), sqrt(1 - $a))), 3);
    }

    public static function ringForDistance(float $distanceKm): ?string
    {
        foreach (config('crime_report.rings') as $ring) {
            if ($distanceKm >= $ring['min'] && $distanceKm <= $ring['max']) {
                return $ring['key'];
            }
        }

        return null;
    }
}
