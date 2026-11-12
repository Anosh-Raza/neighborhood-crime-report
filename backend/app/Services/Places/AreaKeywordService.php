<?php

namespace App\Services\Places;

/**
 * Trace a listing URL / address and derive the main area keyword(s)
 * used for Google News search (e.g. Saadi Garden → Saadi Town).
 */
class AreaKeywordService
{
    /**
     * @return array{
     *   primary: string,
     *   aliases: string[],
     *   search_terms: string[],
     *   locality: ?string,
     *   city: ?string,
     *   country_code: ?string
     * }
     */
    public function resolve(?string $listingUrl, ?string $address, ?string $city = null, ?string $countryCode = null): array
    {
        $parts = $this->partsFromAddress($address);
        $fromUrl = $this->partsFromUrl((string) $listingUrl);

        $locality = $fromUrl['locality'] ?? $parts['locality'] ?? null;
        $block = $fromUrl['block'] ?? $parts['block'] ?? null;
        $scheme = $fromUrl['scheme'] ?? $parts['scheme'] ?? null;
        $city = $city ?: ($fromUrl['city'] ?? $parts['city'] ?? null);
        $countryCode = $countryCode ?: ($fromUrl['country_code'] ?? $parts['country_code'] ?? null);

        // Prefer society/town name over block-level text.
        $base = $locality ?: ($scheme ?: ($city ?: 'Unknown area'));
        $base = $this->titleCase($this->stripNoise($base));

        $aliases = $this->expandAliases($base);
        // Drop block-only tokens from aliases; keep scheme as secondary search.
        if ($scheme) {
            $aliases[] = $this->titleCase($scheme);
        }

        $aliases = array_values(array_unique(array_filter($aliases, fn ($a) => $a !== '' && mb_strtolower($a) !== mb_strtolower($city ?? ''))));

        // Prefer "Town" form as the headline keyword when Garden/Society maps to it
        // (matches how people search Google News for areas like Saadi Town).
        $primary = $base;
        foreach ($aliases as $alias) {
            if (preg_match('/\btown\b/i', $alias) && preg_match('/\b(garden|society|phase)\b/i', $base)) {
                $primary = $alias;
                break;
            }
        }

        $searchTerms = array_values(array_unique(array_filter(array_merge([$primary, $base], $aliases))));

        return [
            'primary' => $primary,
            'aliases' => array_values(array_filter($aliases, fn ($a) => mb_strtolower($a) !== mb_strtolower($primary))),
            'search_terms' => $searchTerms,
            'locality' => $locality ? $this->titleCase($locality) : null,
            'block' => $block,
            'scheme' => $scheme ? $this->titleCase($scheme) : null,
            'city' => $city,
            'country_code' => $countryCode ? strtoupper($countryCode) : null,
        ];
    }

    /**
     * @return array{locality?: string, block?: string, scheme?: string, city?: string, country_code?: string}
     */
    private function partsFromUrl(string $url): array
    {
        if ($url === '') {
            return [];
        }

        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $path = urldecode((string) parse_url($url, PHP_URL_PATH));

        if (! str_contains($host, 'zameen.com')) {
            return [];
        }

        if (! preg_match('#/Property/([^/]+?)(?:-\d+)+#i', $path, $m) && ! preg_match('#/Property/([^/?]+)#i', $path, $m)) {
            return [];
        }

        $slug = preg_replace('/_+/', ' ', $m[1]) ?? $m[1];
        $slug = preg_replace('/\s*-\s*/', ' ', $slug) ?? $slug;
        $slug = preg_replace('/\b(for sale|for rent|commercial|residential|plot|house|flat|apartment|bedroom|sqyd|sqyds|marla|kanal|\d+\s*ft|road facing).*$/i', '', $slug) ?? $slug;
        $slug = trim(preg_replace('/\s+/', ' ', mb_strtolower($slug)) ?? $slug);
        $slug = $this->dedupePhrase($slug);

        $out = ['country_code' => 'PK'];
        if (preg_match('/\bblock\s*(\d+|[a-z])\b/i', $slug, $bm)) {
            $out['block'] = 'Block '.$bm[1];
        }
        if (preg_match('/\bscheme\s*(\d+)\b/i', $slug, $sm)) {
            $out['scheme'] = 'Scheme '.$sm[1];
        }

        // Society/locality: strip block/scheme tails.
        $locality = preg_replace('/\bblock\s*(\d+|[a-z])\b.*$/i', '', $slug) ?? $slug;
        $locality = preg_replace('/\bscheme\s*\d+\b.*$/i', '', $locality) ?? $locality;
        $locality = trim($locality);
        if ($locality !== '') {
            $out['locality'] = $locality;
        }

        foreach (['karachi', 'lahore', 'islamabad', 'rawalpindi', 'peshawar', 'multan', 'faisalabad'] as $c) {
            if (str_contains($slug, $c)) {
                $out['city'] = ucfirst($c);
                break;
            }
        }
        if (! isset($out['city']) && $locality !== '') {
            foreach (['saadi', 'bahria', 'gulshan', 'clifton', 'nazimabad', 'gulistan', 'scheme'] as $hint) {
                if (str_contains($locality, $hint) || str_contains($slug, $hint)) {
                    $out['city'] = 'Karachi';
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @return array{locality?: string, block?: string, scheme?: string, city?: string, country_code?: string}
     */
    private function partsFromAddress(?string $address): array
    {
        if (! $address) {
            return [];
        }

        $raw = trim($address);
        $chunks = array_values(array_filter(array_map('trim', preg_split('/,/', $raw) ?: [])));
        $out = [];

        foreach ($chunks as $chunk) {
            $low = mb_strtolower($chunk);
            if (preg_match('/\bblock\s*[- ]?\s*(\d+|[a-z])\b/i', $chunk, $bm)) {
                $out['block'] = 'Block '.$bm[1];
            }
            if (preg_match('/\bscheme\s*(\d+)\b/i', $chunk, $sm)) {
                $out['scheme'] = 'Scheme '.$sm[1];
            }
            if (preg_match('/\b(garden|town|society|phase|colony|housing)\b/i', $chunk) && empty($out['locality'])) {
                // "Saadi Garden - Block 6" → Saadi Garden
                $loc = preg_replace('/\s*[-–]\s*block.*$/i', '', $chunk) ?? $chunk;
                $loc = preg_replace('/\bblock\s*[- ]?\s*(\d+|[a-z])\b/i', '', $loc) ?? $loc;
                $out['locality'] = trim($loc);
            }
            if (in_array($low, ['karachi', 'lahore', 'islamabad', 'rawalpindi', 'peshawar', 'multan', 'faisalabad'], true)) {
                $out['city'] = ucfirst($low);
            }
            if (in_array($low, ['pakistan', 'پاکستان'], true)) {
                $out['country_code'] = 'PK';
            }
            if ($low === 'sindh' || $low === 'punjab') {
                $out['country_code'] = $out['country_code'] ?? 'PK';
            }
        }

        if (empty($out['locality']) && $chunks !== []) {
            // First chunk often holds the society name.
            $first = preg_replace('/\s*[-–]\s*block.*$/i', '', $chunks[0]) ?? $chunks[0];
            $first = preg_replace('/\bblock\s*[- ]?\s*(\d+|[a-z])\b/i', '', $first) ?? $first;
            $first = trim($first);
            if ($first !== '' && ! preg_match('/^\d/', $first)) {
                $out['locality'] = $first;
            }
        }

        return $out;
    }

    /**
     * @return string[]
     */
    private function expandAliases(string $base): array
    {
        $aliases = [];
        $low = mb_strtolower($base);

        if (preg_match('/^(.+?)\s+garden$/i', $base, $m)) {
            $stem = trim($m[1]);
            $aliases[] = $stem.' Town';
            $aliases[] = $stem.' Society';
            $aliases[] = $stem.' Garden';
        } elseif (preg_match('/^(.+?)\s+town$/i', $base, $m)) {
            $stem = trim($m[1]);
            $aliases[] = $stem.' Garden';
            $aliases[] = $stem.' Society';
            $aliases[] = $stem.' Town';
        } elseif (preg_match('/^(.+?)\s+society$/i', $base, $m)) {
            $stem = trim($m[1]);
            $aliases[] = $stem.' Town';
            $aliases[] = $stem.' Garden';
        }

        // Known Karachi twin names.
        if (str_contains($low, 'saadi')) {
            $aliases[] = 'Saadi Town';
            $aliases[] = 'Saadi Garden';
        }

        return array_map(fn ($a) => $this->titleCase($a), $aliases);
    }

    private function stripNoise(string $text): string
    {
        $text = preg_replace('/\bblock\s*[- ]?\s*(\d+|[a-z])\b/i', '', $text) ?? $text;

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    private function dedupePhrase(string $text): string
    {
        $joined = trim($text);
        if (preg_match('/^(.+?)\s+\1(\s+.+)?$/u', $joined, $m)) {
            return trim($m[1].($m[2] ?? ''));
        }

        return $joined;
    }

    private function titleCase(string $text): string
    {
        return implode(' ', array_map(
            fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)).mb_substr($w, 1),
            preg_split('/\s+/', mb_strtolower(trim($text))) ?: []
        ));
    }
}
