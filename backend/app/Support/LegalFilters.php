<?php

namespace App\Support;

class LegalFilters
{
    public static function sanitizeSummary(string $text): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        // Strip common identity patterns; LLM prompt also forbids naming people.
        $text = preg_replace('/\b(Mr|Mrs|Ms|Miss|Dr)\.?\s+[A-Z][a-z]+(?:\s+[A-Z][a-z]+)?\b/', '[person]', $text) ?? $text;
        $text = preg_replace('/\b[A-Z][a-z]+\s+[A-Z][a-z]+\b(?=.*(victim|suspect|accused|arrested))/i', '[person]', $text) ?? $text;

        if (mb_strlen($text) > 280) {
            $text = mb_substr($text, 0, 277).'...';
        }

        return $text;
    }

    public static function disclaimer(): string
    {
        return (string) config('crime_report.disclaimer');
    }
}
