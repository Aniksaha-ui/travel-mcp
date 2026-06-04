<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Str;
use Throwable;

class SupportTicketChatParser
{
    public function __construct(
        private readonly TravelLlmClient $travelLlmClient,
    ) {
    }

    public function shouldHandle(string $message): bool
    {
        $normalized = Str::lower(trim($message));

        if ($normalized === '') {
            return false;
        }

        $hasActionKeyword = preg_match('/\b(create|open|raise|make|submit|generate|log|register)\b/u', $normalized) === 1;
        $hasSupportKeyword = preg_match('/\b(ticket|support|complaint)\b/u', $normalized) === 1;

        return $hasActionKeyword && $hasSupportKeyword;
    }

    /**
     * @return array{
     *     title: string|null,
     *     description: string|null,
     *     remarks: string|null,
     *     needs_more_information: bool
     * }
     */
    public function parse(string $message): array
    {
        $decoded = [];

        try {
            $decoded = $this->requestTicketIntent($message);
        } catch (Throwable $exception) {
            report($exception);
        }

        $description = $this->normalizeDescription(
            $decoded['description'] ?? $decoded['ticket_description'] ?? $this->fallbackDescriptionFromMessage($message),
        );
        $title = $this->normalizeTitle(
            $decoded['title'] ?? $decoded['ticket_title'] ?? $this->fallbackTitleFromDescription($description),
        );
        $remarks = $this->normalizeRemarks($decoded['remarks'] ?? $decoded['ticket_remarks'] ?? null);

        $needsMoreInformation = $this->normalizeBoolean($decoded['needs_more_information'] ?? false);

        if ($description === null || $title === null) {
            $needsMoreInformation = true;
        }

        return [
            'title' => $title,
            'description' => $description,
            'remarks' => $remarks,
            'needs_more_information' => $needsMoreInformation,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function requestTicketIntent(string $message): array
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

        throw new \RuntimeException('Support ticket intent LLM returned invalid JSON.', 502);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You extract support ticket details from customer chat messages.

Return only valid JSON with this exact shape:
{"title": string|null, "description": string|null, "remarks": string|null, "needs_more_information": boolean}

Rules:
- The customer is asking to create or open a support ticket.
- Write a concise ticket title suitable for a ticket list. Keep it under 120 characters.
- Write the description as a clear support issue summary using only the details the customer actually provided.
- Include booking IDs, transaction references, dates, hotel names, trip names, package names, or payment issues when they are present.
- Do not invent facts, IDs, or promises.
- Use "remarks" only when the customer clearly gives a separate short note or preference. Otherwise return null.
- If the message is too vague to create a meaningful ticket, set "needs_more_information" to true and return null for missing fields.
- Never return markdown, explanations, or extra keys.

Examples:
User: Please create a ticket because my refund for booking BK-104 has not arrived yet.
JSON: {"title":"Refund pending for booking BK-104","description":"The customer says the refund for booking BK-104 has not arrived yet and wants support to check it.","remarks":null,"needs_more_information":false}

User: Open a support ticket.
JSON: {"title":null,"description":null,"remarks":null,"needs_more_information":true}
PROMPT;
    }

    private function normalizeTitle(mixed $value): ?string
    {
        $normalized = $this->normalizeText($value);

        if ($normalized === null) {
            return null;
        }

        return Str::limit($normalized, 120, '...');
    }

    private function normalizeDescription(mixed $value): ?string
    {
        $normalized = $this->normalizeText($value);

        if ($normalized === null) {
            return null;
        }

        return Str::limit($normalized, 255, '...');
    }

    private function normalizeRemarks(mixed $value): ?string
    {
        $normalized = $this->normalizeText($value);

        if ($normalized === null) {
            return null;
        }

        return Str::limit($normalized, 255, '...');
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized, " \t\n\r\0\x0B,;:-");

        if ($normalized === '') {
            return null;
        }

        return Str::ucfirst($normalized);
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value === 1;
        }

        if (! is_string($value)) {
            return false;
        }

        return in_array(Str::lower(trim($value)), ['1', 'true', 'yes'], true);
    }

    private function fallbackDescriptionFromMessage(string $message): ?string
    {
        $normalized = trim($message);

        if ($normalized === '') {
            return null;
        }

        $patterns = [
            '/^(?:please\s+)?(?:can\s+you\s+)?(?:kindly\s+)?(?:create|open|raise|make|submit|generate|log|register)\s+(?:me\s+)?(?:an?\s+)?(?:support\s+)?(?:ticket|request|complaint)(?:\s+(?:for|about|regarding|because|that))?\s*/iu',
            '/^(?:please\s+)?(?:i\s+want\s+to\s+|i\s+need\s+to\s+)?(?:create|open|raise|make)\s+(?:an?\s+)?(?:support\s+)?ticket(?:\s+(?:for|about|regarding|because|that))?\s*/iu',
        ];

        foreach ($patterns as $pattern) {
            $cleaned = preg_replace($pattern, '', $normalized, 1);

            if (! is_string($cleaned)) {
                continue;
            }

            $candidate = trim($cleaned, " \t\n\r\0\x0B,.;:-");

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function fallbackTitleFromDescription(?string $description): ?string
    {
        if (! is_string($description) || trim($description) === '') {
            return null;
        }

        $sentence = preg_split('/(?<=[.!?])\s+/u', $description)[0] ?? $description;
        $sentence = trim($sentence);
        $sentence = trim($sentence, " \t\n\r\0\x0B,.;:-");

        if ($sentence === '') {
            return null;
        }

        return Str::limit(Str::ucfirst($sentence), 120, '...');
    }
}
