<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;
use RuntimeException;

class TravelQueryParser
{
    public function __construct(
        private readonly TravelLlmClient $travelLlmClient,
    ) {
    }

    /**
     * @var array<int, string>
     */
    private const RESOURCES = ['trips', 'packages', 'hotels'];

    /**
     * @return array{location: string|null, resources: array<int, string>}
     */
    public function parse(string $message): array
    {
        $decoded = $this->requestTravelIntent($message);

        return [
            'location' => $this->normalizeLocation($decoded['location'] ?? null),
            'resources' => $this->normalizeResources($decoded['resources'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestTravelIntent(string $message): array
    {
        $text = $this->travelLlmClient->chat(
            systemPrompt: $this->systemPrompt(),
            userPrompt: $message,
            temperature: 0,
        );

        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $text, $matches) === 1) {
            $decoded = json_decode($matches[0], true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        throw new RuntimeException('Travel intent LLM returned invalid JSON.', 502);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You extract travel search intent from customer messages for a travel API.

Return only valid JSON with this exact shape:
{"location": string|null, "resources": ["trips"|"packages"|"hotels", ...]}

Rules:
- Detect the requested location from the message.
- Correct obvious spelling mistakes in well-known destinations when you are confident.
- Normalize the location into readable title case, keeping apostrophes when appropriate.
- Map any request for trip/trips to "trips".
- Map any request for package/packages to "packages".
- Map any request for hotel/hotels to "hotels".
- If the user wants general or all information without naming a specific resource type, return all three resources.
- If the user mentions only one or two resource types, return only those.
- If no location can be inferred confidently, set "location" to null.
- Never return explanations, markdown, or extra keys.

Examples:
User: fetch all information of cox's bazer trips
JSON: {"location":"Cox's Bazar","resources":["trips"]}

User: show me all information for dubai
JSON: {"location":"Dubai","resources":["trips","packages","hotels"]}

User: need hotel and package in malaysia
JSON: {"location":"Malaysia","resources":["packages","hotels"]}
PROMPT;
    }

    private function normalizeLocation(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $location = trim((string) $value);
        $location = preg_replace('/\s+/', ' ', $location);
        $location = trim((string) $location, " \t\n\r\0\x0B,.;:-");

        return $location !== '' ? $location : null;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeResources(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];
        $normalized = [];

        foreach ($items as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $resource = match (Str::lower(trim((string) $item))) {
                'trip', 'trips' => 'trips',
                'package', 'packages' => 'packages',
                'hotel', 'hotels' => 'hotels',
                default => null,
            };

            if ($resource !== null) {
                $normalized[] = $resource;
            }
        }

        $normalized = array_values(array_unique($normalized));

        return $normalized === [] ? self::RESOURCES : $normalized;
    }
}
