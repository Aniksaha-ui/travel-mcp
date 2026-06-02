<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;

class TravelQueryParser
{
    /**
     * @return array{location: string|null, resources: array<int, string>}
     */
    public function parse(string $message): array
    {
        $resources = $this->detectResources($message);

        return [
            'location' => $this->extractLocation($message, $resources),
            'resources' => $resources === [] ? ['trips', 'packages', 'hotels'] : $resources,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function detectResources(string $message): array
    {
        $lower = Str::lower($message);
        $resources = [];

        if (preg_match('/\btrip\b|\btrips\b/u', $lower) === 1) {
            $resources[] = 'trips';
        }

        if (preg_match('/\bpackage\b|\bpackages\b/u', $lower) === 1) {
            $resources[] = 'packages';
        }

        if (preg_match('/\bhotel\b|\bhotels\b/u', $lower) === 1) {
            $resources[] = 'hotels';
        }

        return $resources;
    }

    /**
     * @param  array<int, string>  $resources
     */
    private function extractLocation(string $message, array $resources): ?string
    {
        $resourcePattern = $resources === []
            ? 'trip|trips|package|packages|hotel|hotels'
            : implode('|', array_map(static fn (string $resource): string => preg_quote(rtrim($resource, 's'), '/').'s?', $resources));

        $patterns = [
            '/\b(?:about|for|in|at|to)\s+(.+?)(?=(?:\s*(?:trip|trips|package|packages|hotel|hotels)\b|[?.!,]|$))/iu',
            '/\b(?:visit|visiting|travel to|traveling to)\s+(.+?)(?=(?:[?.!,]|$))/iu',
            '/\b'.$resourcePattern.'\b.*?\b(?:in|for|at)\s+(.+?)(?=(?:[?.!,]|$))/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                $location = $this->cleanLocation($matches[1] ?? '');

                if ($location !== null) {
                    return $location;
                }
            }
        }

        return null;
    }

    private function cleanLocation(string $value): ?string
    {
        $location = trim($value);
        $location = preg_replace('/\b(?:trip|trips|package|packages|hotel|hotels|and|all|information|details)\b/iu', '', $location);
        $location = preg_replace('/\s+/', ' ', (string) $location);
        $location = trim((string) $location, " \t\n\r\0\x0B,.;:-");

        return $location !== '' ? $location : null;
    }
}
