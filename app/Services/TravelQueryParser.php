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
     * @var array<int, string>
     */
    private const QUESTION_FOCI = ['general', 'location', 'price', 'status', 'availability', 'details'];

    /**
     * @return array{
     *     search_term: string|null,
     *     location: string|null,
     *     resources: array<int, string>,
     *     question_focus: string
     * }
     */
    public function parse(string $message): array
    {
        $decoded = $this->requestTravelIntent($message);
        $resources = $this->normalizeResources($decoded['resources'] ?? null);
        $searchTerm = $this->normalizeSearchTerm($decoded['search_term'] ?? $decoded['location'] ?? null)
            ?? $this->inferSearchTermFromMessage($message, $resources);

        return [
            'search_term' => $searchTerm,
            'location' => $this->normalizeLocation($decoded['location'] ?? null),
            'resources' => $resources,
            'question_focus' => $this->normalizeQuestionFocus($decoded['question_focus'] ?? null),
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
{"search_term": string|null, "location": string|null, "resources": ["trips"|"packages"|"hotels", ...], "question_focus": "general"|"location"|"price"|"status"|"availability"|"details"}

Rules:
- Detect the best search term to send to the travel API. This can be a destination like "Dubai" or a specific entity name like "Sea Place Hotel".
- Detect the requested location from the message only when the user clearly names a destination.
- Correct obvious spelling mistakes in well-known destinations when you are confident.
- Normalize the search term and location into readable title case, keeping apostrophes when appropriate.
- Map any request for trip/trips to "trips".
- Map any request for package/packages to "packages".
- Map any request for hotel/hotels to "hotels".
- If the user wants general or all information without naming a specific resource type, return all three resources.
- If the user mentions only one or two resource types, return only those.
- If the user asks for a specific fact like location, price, status, or availability, set "question_focus" accordingly. Otherwise use "general" or "details" when they ask for broader item information.
- If no destination can be inferred confidently, set "location" to null.
- If the user names a specific hotel, package, or trip but not its destination, set "search_term" to that item name and "location" to null.
- If no useful API search term can be inferred confidently, set "search_term" to null.
- Never return explanations, markdown, or extra keys.

Examples:
User: fetch all information of cox's bazer trips
JSON: {"search_term":"Cox's Bazar","location":"Cox's Bazar","resources":["trips"],"question_focus":"general"}

User: show me all information for dubai
JSON: {"search_term":"Dubai","location":"Dubai","resources":["trips","packages","hotels"],"question_focus":"general"}

User: need hotel and package in malaysia
JSON: {"search_term":"Malaysia","location":"Malaysia","resources":["packages","hotels"],"question_focus":"general"}

User: what is the location of sea place hotel
JSON: {"search_term":"Sea Place Hotel","location":null,"resources":["hotels"],"question_focus":"location"}
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

    private function normalizeSearchTerm(mixed $value): ?string
    {
        return $this->normalizeLocation($value);
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

    private function normalizeQuestionFocus(mixed $value): string
    {
        if (! is_scalar($value)) {
            return 'general';
        }

        return match (Str::lower(trim((string) $value))) {
            'location', 'address', 'place' => 'location',
            'price', 'cost', 'fare', 'rate' => 'price',
            'status' => 'status',
            'availability', 'available', 'vacancy' => 'availability',
            'details', 'detail', 'information', 'info' => 'details',
            'general', 'overview', 'summary', '' => 'general',
            default => in_array(Str::lower(trim((string) $value)), self::QUESTION_FOCI, true)
                ? Str::lower(trim((string) $value))
                : 'general',
        };
    }

    /**
     * @param  array<int, string>  $resources
     */
    private function inferSearchTermFromMessage(string $message, array $resources): ?string
    {
        $patterns = [
            '/\b(?:location|address|price|status|availability|details|detail|information|info)\s+(?:of|for)\s+(.+)$/iu',
            '/\babout\s+(.+)$/iu',
            '/\bfor\s+(.+)$/iu',
            '/\b(?:in|at|to)\s+(.+)$/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, trim($message), $matches) !== 1) {
                continue;
            }

            $candidate = $this->normalizeSearchCandidate($matches[1] ?? '', $resources);

            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $resources
     */
    private function normalizeSearchCandidate(string $candidate, array $resources): ?string
    {
        $candidate = trim($candidate);
        $candidate = preg_replace('/[?.!,;:]+$/u', '', $candidate) ?? $candidate;
        $candidate = preg_replace('/\s+/', ' ', $candidate) ?? $candidate;
        $candidate = trim($candidate);

        if ($candidate === '') {
            return null;
        }

        if ($resources !== self::RESOURCES) {
            $resourceLabels = array_map(
                fn (string $resource): string => match ($resource) {
                    'trips' => 'trip',
                    'packages' => 'package',
                    default => 'hotel',
                },
                $resources,
            );
            $resourceTokens = [...$resourceLabels, ...array_map(
                fn (string $label): string => $label.'s',
                $resourceLabels,
            )];
            $resourcePattern = implode('|', array_map(
                fn (string $token): string => preg_quote($token, '/'),
                $resourceTokens,
            ));

            if ($resourcePattern !== '') {
                $candidateWithoutTrailingResource = preg_replace(
                    '/\b(?:'.$resourcePattern.')\b$/iu',
                    '',
                    $candidate,
                );

                if (is_string($candidateWithoutTrailingResource)) {
                    $candidate = trim($candidateWithoutTrailingResource);
                }
            }
        }

        return $this->normalizeSearchTerm($candidate);
    }
}
