<?php

namespace App\Support;

class GridCell
{
    /**
     * ~1 km grid cells near the equator; good enough for location_cache reuse.
     */
    public static function key(float $lat, float $lng, float $precision = 0.01): string
    {
        $latCell = floor($lat / $precision);
        $lngCell = floor($lng / $precision);

        return sprintf('g:%d:%d', $latCell, $lngCell);
    }
}
