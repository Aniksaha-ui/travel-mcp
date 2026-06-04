<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

class BookingChatParser
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

        $hasActionKeyword = preg_match('/\b(book|booking|reserve|reservation)\b/u', $normalized) === 1;
        $hasResourceKeyword = preg_match('/\b(trip|seat|hotel|room|check[\s-]?in|check[\s-]?out|stay)\b/u', $normalized) === 1;

        return $hasActionKeyword && $hasResourceKeyword;
    }

    /**
     * @return array{
     *     action: 'book_trip'|'book_hotel'|null,
     *     trip_id: int|null,
     *     trip_name: string|null,
     *     seat_numbers: array<int, string>,
     *     hotel_id: int|null,
     *     hotel_room_id: int|null,
     *     check_in_date: string|null,
     *     check_out_date: string|null,
     *     total_persons: int|null,
     *     payment_method: string|null,
     *     payment_reference: string|null,
     *     needs_more_information: bool
     * }
     */
    public function parse(string $message): array
    {
        $decoded = [];

        try {
            $decoded = $this->requestIntent($message);
        } catch (Throwable $exception) {
            report($exception);
        }

        $action = $this->normalizeAction($decoded['action'] ?? null)
            ?? $this->inferActionFromMessage($message);
        $paymentMethod = $this->normalizePaymentMethod($decoded['payment_method'] ?? null)
            ?? $this->inferPaymentMethod($message);
        $paymentReference = $this->normalizePaymentReference($decoded['payment_reference'] ?? null)
            ?? $this->inferPaymentReference($message, $paymentMethod);

        $result = [
            'action' => $action,
            'trip_id' => $this->normalizeInt($decoded['trip_id'] ?? null)
                ?? $this->inferTripIdentifier($message),
            'trip_name' => $this->normalizeTripName($decoded['trip_name'] ?? null)
                ?? $this->inferTripName($message),
            'seat_numbers' => $this->normalizeSeatNumbers($decoded['seat_numbers'] ?? null)
                ?: $this->inferSeatNumbers($message),
            'hotel_id' => $this->normalizeInt($decoded['hotel_id'] ?? null)
                ?? $this->inferHotelIdentifier($message),
            'hotel_room_id' => $this->normalizeInt($decoded['hotel_room_id'] ?? $decoded['room_id'] ?? null)
                ?? $this->inferRoomIdentifier($message),
            'check_in_date' => $this->normalizeDate($decoded['check_in_date'] ?? null)
                ?? $this->inferDate($message, ['check in', 'check-in', 'from']),
            'check_out_date' => $this->normalizeDate($decoded['check_out_date'] ?? null)
                ?? $this->inferDate($message, ['check out', 'check-out', 'to', 'until']),
            'total_persons' => $this->normalizeInt($decoded['total_persons'] ?? $decoded['guests'] ?? null)
                ?? $this->inferGuests($message),
            'payment_method' => $paymentMethod,
            'payment_reference' => $paymentReference,
            'needs_more_information' => $this->normalizeBoolean($decoded['needs_more_information'] ?? false),
        ];

        if ($result['trip_name'] !== null && ! $this->messageExplicitlyMentionsTripId($message)) {
            $result['trip_id'] = null;
        }

        if ($this->requiresMoreInformation($result)) {
            $result['needs_more_information'] = true;
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestIntent(string $message): array
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

        throw new \RuntimeException('Booking intent LLM returned invalid JSON.', 502);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You extract authenticated travel booking details from customer chat messages.

Return only valid JSON with this exact shape:
{
  "action": "book_trip"|"book_hotel"|null,
  "trip_id": integer|null,
  "trip_name": string|null,
  "seat_numbers": [string, ...],
  "hotel_id": integer|null,
  "hotel_room_id": integer|null,
  "check_in_date": string|null,
  "check_out_date": string|null,
  "total_persons": integer|null,
  "payment_method": "card"|"bkash"|"nagad"|"internet_banking"|null,
  "payment_reference": string|null,
  "needs_more_information": boolean
}

Rules:
- Use "book_trip" when the customer clearly wants to reserve trip seats.
- Use "book_hotel" when the customer clearly wants to reserve a hotel room.
- Prefer exact numeric ids when the customer provides them, such as trip id, hotel id, or room id.
- If the customer names a trip instead of giving a trip id, put that value in "trip_name".
- Keep seat numbers as short strings like "A1" or "B3".
- Normalize dates to YYYY-MM-DD.
- Use "payment_reference" for the card number or wallet/account number when the customer provides it.
- Do not invent ids, seat numbers, dates, guest counts, or payment information.
- If required booking details are missing, set "needs_more_information" to true.
- Never return markdown, explanations, or extra keys.

Examples:
User: Book trip id 15 seat A1 and A2 with bkash 01700000000
JSON: {"action":"book_trip","trip_id":15,"trip_name":null,"seat_numbers":["A1","A2"],"hotel_id":null,"hotel_room_id":null,"check_in_date":null,"check_out_date":null,"total_persons":null,"payment_method":"bkash","payment_reference":"01700000000","needs_more_information":false}

User: Book the Beach Flight trip
JSON: {"action":"book_trip","trip_id":null,"trip_name":"Beach Flight","seat_numbers":[],"hotel_id":null,"hotel_room_id":null,"check_in_date":null,"check_out_date":null,"total_persons":null,"payment_method":null,"payment_reference":null,"needs_more_information":true}

User: Book hotel id 9 room id 21 from 2026-06-10 to 2026-06-12 for 2 guests with card 4242
JSON: {"action":"book_hotel","trip_id":null,"trip_name":null,"seat_numbers":[],"hotel_id":9,"hotel_room_id":21,"check_in_date":"2026-06-10","check_out_date":"2026-06-12","total_persons":2,"payment_method":"card","payment_reference":"4242","needs_more_information":false}

User: I want to reserve a trip
JSON: {"action":"book_trip","trip_id":null,"trip_name":null,"seat_numbers":[],"hotel_id":null,"hotel_room_id":null,"check_in_date":null,"check_out_date":null,"total_persons":null,"payment_method":null,"payment_reference":null,"needs_more_information":true}
PROMPT;
    }

    private function normalizeAction(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        return match (Str::lower(trim((string) $value))) {
            'book_trip', 'trip', 'trip_booking' => 'book_trip',
            'book_hotel', 'hotel', 'hotel_booking' => 'book_hotel',
            default => null,
        };
    }

    private function inferActionFromMessage(string $message): ?string
    {
        $normalized = Str::lower($message);

        if (preg_match('/\b(trip|seat|seats)\b/u', $normalized) === 1) {
            return 'book_trip';
        }

        if (preg_match('/\b(hotel|room|check[\s-]?in|check[\s-]?out|stay)\b/u', $normalized) === 1) {
            return 'book_hotel';
        }

        return null;
    }

    private function normalizeInt(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return ctype_digit($normalized) && (int) $normalized > 0
            ? (int) $normalized
            : null;
    }

    private function normalizeTripName(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized, " \t\n\r\0\x0B,.;:-");

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array<int, string>|mixed  $value
     * @return array<int, string>
     */
    private function normalizeSeatNumbers(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];
        $normalized = [];

        foreach ($items as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $seat = Str::upper(trim((string) $item));
            $seat = preg_replace('/[^A-Z0-9]/u', '', $seat) ?? $seat;

            if ($seat !== '' && preg_match('/^[A-Z]{1,3}\d{1,3}$/u', $seat) === 1) {
                $normalized[] = $seat;
            }
        }

        return array_values(array_unique($normalized));
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizePaymentMethod(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        return match (Str::lower(trim((string) $value))) {
            'card', 'credit card', 'debit card' => 'card',
            'bkash', 'b-kash' => 'bkash',
            'nagad' => 'nagad',
            'internet_banking', 'internet banking', 'bank transfer' => 'internet_banking',
            default => null,
        };
    }

    private function normalizePaymentReference(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        $normalized = preg_replace('/\s+/', '', $normalized) ?? $normalized;

        return $normalized !== '' ? $normalized : null;
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

    private function inferTripIdentifier(string $message): ?int
    {
        return $this->inferIdentifierFromPatterns($message, [
            '/\btrip\s+id\s*(?:#|:)?\s*(\d+)/iu',
            '/\btrip\s*#\s*(\d+)/iu',
            '/\bbook\s+trip\s+(\d+)\b/iu',
            '/\btrip\s+(\d+)\b/iu',
        ]);
    }

    private function inferHotelIdentifier(string $message): ?int
    {
        return $this->inferIdentifierFromPatterns($message, [
            '/\bhotel\s+id\s*(?:#|:)?\s*(\d+)/iu',
            '/\bhotel\s*#\s*(\d+)/iu',
            '/\bhotel\s+(\d+)\b/iu',
        ]);
    }

    private function inferRoomIdentifier(string $message): ?int
    {
        return $this->inferIdentifierFromPatterns($message, [
            '/\broom\s+id\s*(?:#|:)?\s*(\d+)/iu',
            '/\broom\s*#\s*(\d+)/iu',
            '/\broom\s+(\d+)\b/iu',
        ]);
    }

    /**
     * @param  array<int, string>  $patterns
     */
    private function inferIdentifierFromPatterns(string $message, array $patterns): ?int
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                return isset($matches[1]) ? (int) $matches[1] : null;
            }
        }

        return null;
    }

    private function messageExplicitlyMentionsTripId(string $message): bool
    {
        $patterns = [
            '/\btrip\s+id\s*(?:#|:)?\s*\d+/iu',
            '/\btrip\s*#\s*\d+\b/iu',
            '/\bbook\s+trip\s+\d+\b/iu',
            '/\btrip\s+\d+\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function inferSeatNumbers(string $message): array
    {
        preg_match_all('/\b([A-Z]{1,3}\d{1,3})\b/u', Str::upper($message), $matches);

        return $this->normalizeSeatNumbers($matches[1] ?? []);
    }

    private function inferTripName(string $message): ?string
    {
        $patterns = [
            '/\bbook\s+(?:the\s+)?(.+?)\s+trip\b/iu',
            '/\breserve\s+(?:the\s+)?(.+?)\s+trip\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) !== 1) {
                continue;
            }

            $candidate = $this->normalizeTripName($matches[1] ?? null);

            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $labels
     */
    private function inferDate(string $message, array $labels): ?string
    {
        foreach ($labels as $label) {
            $pattern = '/'.preg_quote($label, '/').'\s*(?:date\s*)?(?:is\s*)?([0-9]{4}-[0-9]{2}-[0-9]{2}|[0-9]{2}\/[0-9]{2}\/[0-9]{4})/iu';

            if (preg_match($pattern, $message, $matches) === 1) {
                return $this->normalizeDate($matches[1] ?? null);
            }
        }

        preg_match_all('/\b([0-9]{4}-[0-9]{2}-[0-9]{2})\b/u', $message, $matches);

        if (($matches[1] ?? []) !== []) {
            return $this->normalizeDate($matches[1][0]);
        }

        return null;
    }

    private function inferGuests(string $message): ?int
    {
        $patterns = [
            '/\bfor\s+(\d+)\s+(?:guest|guests|person|persons|people)\b/iu',
            '/\b(\d+)\s+(?:guest|guests|person|persons|people)\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $matches) === 1) {
                return isset($matches[1]) ? (int) $matches[1] : null;
            }
        }

        return null;
    }

    private function inferPaymentMethod(string $message): ?string
    {
        return $this->normalizePaymentMethod(
            preg_match('/\b(internet banking|internet_banking|bank transfer|card|bkash|b-kash|nagad)\b/iu', $message, $matches) === 1
                ? ($matches[1] ?? null)
                : null,
        );
    }

    private function inferPaymentReference(string $message, ?string $paymentMethod): ?string
    {
        if ($paymentMethod === null || $paymentMethod === 'internet_banking') {
            return null;
        }

        $label = match ($paymentMethod) {
            'bkash' => 'bkash|b-kash',
            'nagad' => 'nagad',
            default => 'card',
        };

        if (preg_match('/\b(?:'.$label.')\b\s*(?:number|no|:)?\s*([A-Za-z0-9-]{4,30})/iu', $message, $matches) === 1) {
            return $this->normalizePaymentReference($matches[1] ?? null);
        }

        return null;
    }

    /**
     * @param  array{
     *     action: 'book_trip'|'book_hotel'|null,
     *     trip_id: int|null,
     *     trip_name: string|null,
     *     seat_numbers: array<int, string>,
     *     hotel_id: int|null,
     *     hotel_room_id: int|null,
     *     check_in_date: string|null,
     *     check_out_date: string|null,
     *     total_persons: int|null,
     *     payment_method: string|null,
     *     payment_reference: string|null,
     *     needs_more_information: bool
     * }  $parsed
     */
    private function requiresMoreInformation(array $parsed): bool
    {
        if ($parsed['action'] === 'book_trip') {
            if ($parsed['trip_id'] === null && $parsed['trip_name'] === null) {
                return true;
            }

            return false;
        }

        if ($parsed['action'] === 'book_hotel') {
            if (
                $parsed['hotel_id'] === null
                || $parsed['hotel_room_id'] === null
                || $parsed['check_in_date'] === null
                || $parsed['check_out_date'] === null
                || $parsed['total_persons'] === null
                || $parsed['payment_method'] === null
            ) {
                return true;
            }

            return in_array($parsed['payment_method'], ['card', 'bkash', 'nagad'], true)
                && $parsed['payment_reference'] === null;
        }

        return true;
    }
}
