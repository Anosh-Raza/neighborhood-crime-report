<?php

namespace App\Support;

class DedupeHash
{
    public static function make(
        string $crimeType,
        ?string $incidentDate,
        string $locationText,
        ?float $lat = null,
        ?float $lng = null,
    ): string {
        $date = $incidentDate ? substr($incidentDate, 0, 10) : 'unknown-date';
        $loc = mb_strtolower(trim(preg_replace('/\s+/', ' ', $locationText) ?? ''));
        $geo = ($lat !== null && $lng !== null)
            ? sprintf('%.3f,%.3f', $lat, $lng)
            : 'nogeo';

        return hash('sha256', implode('|', [
            mb_strtolower($crimeType),
            $date,
            $loc,
            $geo,
        ]));
    }
}
