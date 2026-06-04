<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Arr;
use Throwable;

class TravelChatLlmResponseGenerator
{
    public function __construct(
        private readonly TravelLlmClient $travelLlmClient,
        private readonly TravelChatHtmlRenderer $travelChatHtmlRenderer,
    ) {
    }

    /**
     * @param  array{search_term: string, location: string|null, display_label: string, question_focus: string}  $intent
     * @param  array<string, mixed>  $overview
     * @param  array<int, string>  $requestedResources
     * @return array<string, string>
     */
    public function generate(string $message, array $intent, array $overview, array $requestedResources): array
    {
        $fallback = $this->travelChatHtmlRenderer->render($intent['display_label'], $overview, $requestedResources);

        try {
            $text = $this->travelLlmClient->chat(
                systemPrompt: $this->systemPrompt(),
                userPrompt: $this->userPrompt($message, $intent, $overview, $requestedResources),
                temperature: 0.2,
            );

            $decoded = $this->decodeJson($text);

            return $this->normalizeHtmlPayload($decoded, $fallback);
        } catch (Throwable $exception) {
            report($exception);

            return $fallback;
        }
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You generate customer-facing travel response HTML for a travel API.

Return only valid JSON with this exact shape:
{
  "full": string,
  "summary": string,
  "trips": string,
  "packages": string,
  "hotels": string
}

Rules:
- Each value must be an HTML fragment string, not markdown.
- Use attractive, professional inline-styled HTML suitable for direct rendering in a chat UI.
- Keep the wording natural and human.
- Answer the customer's actual question using the supplied travel data, not just a generic overview.
- If the customer asks for a specific fact such as location, price, status, or availability, state that answer clearly near the start of "summary" and "full".
- Treat "search_term" as the lookup text sent to the travel API. Treat "location" as the confirmed destination only when it is present.
- If there is a clear direct match for a named hotel, package, or trip, mention that item directly before giving broader context.
- The summary should quickly explain what was found for the request.
- Resource sections should be readable and polished, using headings, counts, tables, and concise descriptive blocks when useful.
- If a resource was not requested, return an empty string for that resource section.
- If no matching items are found for a requested resource, explain that politely in HTML.
- If a resource failed, explain that section politely in HTML.
- Never return raw JSON dumps, code fences, scripts, or explanations outside the JSON object.
PROMPT;
    }

    /**
     * @param  array{search_term: string, location: string|null, display_label: string, question_focus: string}  $intent
     * @param  array<string, mixed>  $overview
     * @param  array<int, string>  $requestedResources
     */
    private function userPrompt(string $message, array $intent, array $overview, array $requestedResources): string
    {
        return json_encode([
            'customer_message' => $message,
            'search_term' => $intent['search_term'],
            'location' => $intent['location'],
            'display_label' => $intent['display_label'],
            'question_focus' => $intent['question_focus'],
            'requested_resources' => array_values($requestedResources),
            'presentation_instruction' => $this->travelChatHtmlRenderer->presentationInstruction(),
            'travel_data' => $this->compactOverview($overview, $requestedResources),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * @param  array<string, mixed>  $overview
     * @param  array<int, string>  $requestedResources
     * @return array<string, mixed>
     */
    private function compactOverview(array $overview, array $requestedResources): array
    {
        $payload = [];

        foreach ($requestedResources as $resource) {
            $section = $overview[$resource] ?? null;

            if (! is_array($section)) {
                continue;
            }

            if (($section['error'] ?? false) === true) {
                $payload[$resource] = [
                    'error' => true,
                    'message' => $section['message'] ?? 'Unable to load this section.',
                    'status' => $section['status'] ?? null,
                ];

                continue;
            }

            $items = array_slice($this->extractItems(Arr::get($section, 'data', [])), 0, 8);

            $payload[$resource] = [
                'error' => false,
                'count' => count($this->extractItems(Arr::get($section, 'data', []))),
                'items' => array_map(fn (array $item): array => $this->compactItem($item), $items),
            ];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function extractItems(array $data): array
    {
        $items = Arr::get($data, 'data');

        if (is_array($items) && array_is_list($items)) {
            return array_values(array_filter($items, 'is_array'));
        }

        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, scalar|null>
     */
    private function compactItem(array $item): array
    {
        $compact = [];
        $kept = 0;

        foreach ($item as $key => $value) {
            if ($kept >= 14) {
                break;
            }

            $normalized = $this->normalizeItemValue($value);

            if ($normalized === null) {
                continue;
            }

            $compact[$key] = $normalized;
            $kept++;
        }

        return $compact;
    }

    private function normalizeItemValue(mixed $value): string|int|float|bool|null
    {
        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            if ($trimmed === '') {
                return null;
            }

            return mb_strlen($trimmed) > 220
                ? rtrim(mb_substr($trimmed, 0, 219)).'...'
                : $trimmed;
        }

        if (is_array($value)) {
            $flattened = array_filter(array_map(
                fn (mixed $entry): ?string => is_scalar($entry) ? trim((string) $entry) : null,
                $value,
            ));

            if ($flattened === []) {
                return null;
            }

            $text = implode(', ', $flattened);

            return mb_strlen($text) > 220
                ? rtrim(mb_substr($text, 0, 219)).'...'
                : $text;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $text): array
    {
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

        throw new \RuntimeException('Travel response LLM returned invalid JSON.', 502);
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<string, string>  $fallback
     * @return array<string, string>
     */
    private function normalizeHtmlPayload(array $decoded, array $fallback): array
    {
        $normalized = [
            'full' => $this->normalizedRequiredHtml($decoded, 'full', $fallback),
            'summary' => $this->normalizedRequiredHtml($decoded, 'summary', $fallback),
            'trips' => $this->normalizedOptionalSectionHtml($decoded, 'trips', $fallback),
            'packages' => $this->normalizedOptionalSectionHtml($decoded, 'packages', $fallback),
            'hotels' => $this->normalizedOptionalSectionHtml($decoded, 'hotels', $fallback),
        ];

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<string, string>  $fallback
     */
    private function normalizedRequiredHtml(array $decoded, string $key, array $fallback): string
    {
        $value = $decoded[$key] ?? null;

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : $fallback[$key];
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<string, string>  $fallback
     */
    private function normalizedOptionalSectionHtml(array $decoded, string $key, array $fallback): string
    {
        if (! array_key_exists($key, $decoded)) {
            return $fallback[$key];
        }

        $value = $decoded[$key];

        if (! is_string($value)) {
            return $fallback[$key];
        }

        return trim($value);
    }
}
