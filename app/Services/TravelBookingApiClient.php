<?php

declare(strict_types=1);

namespace App\Services;

use App\Mcp\Tools\Concerns\LogsTravelBookingRequests;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class TravelBookingApiClient
{
    use LogsTravelBookingRequests;

    /**
     * @return array<string, mixed>
     */
    public function fetchTripSummary(string $bearerToken, int $tripId): array
    {
        $endpoint = '/api/user/tripsummery';
        $payload = ['trip_id' => $tripId];
        $url = $this->endpointUrl($endpoint);

        try {
            $this->logTravelBookingRequest(
                resource: 'trip_summary',
                method: 'POST',
                url: $url,
                token: $bearerToken,
                payload: $payload,
            );

            $response = $this->baseRequest($bearerToken)->post($url, $payload);
        } catch (ConnectionException $exception) {
            return $this->bookingErrorResponse(
                resource: 'trip_summary',
                endpoint: $endpoint,
                status: 503,
                message: 'Unable to reach the remote TravelBooking trip summary API.',
                exceptionMessage: $exception->getMessage(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->bookingErrorResponse(
                resource: 'trip_summary',
                endpoint: $endpoint,
                status: 500,
                message: 'An unexpected error occurred while loading trip booking data.',
                exceptionMessage: $exception->getMessage(),
            );
        }

        $data = $this->normalizePayload($response->json());

        if ($response->successful() && ! $this->normalizedResponseFailed($data)) {
            return [
                'error' => false,
                'resource' => 'trip_summary',
                'endpoint' => $url,
                'status' => $response->status(),
                'data' => $data,
            ];
        }

        return $this->bookingErrorResponse(
            resource: 'trip_summary',
            endpoint: $endpoint,
            status: $response->status(),
            message: $this->bookingMessageForStatus('trip_summary', $response->status(), $data),
            response: $response,
        );
    }

    /**
     * @param  array{title: string, description: string, remarks?: string}  $payload
     * @return array<string, mixed>
     */
    public function createTicket(string $bearerToken, array $payload): array
    {
        $endpoint = '/api/user/createTicket';
        $url = $this->endpointUrl($endpoint);

        try {
            $this->logTravelBookingRequest(
                resource: 'tickets',
                method: 'POST',
                url: $url,
                token: $bearerToken,
                payload: $payload,
            );

            $response = $this->baseRequest($bearerToken)->post($url, $payload);
        } catch (ConnectionException $exception) {
            return $this->ticketErrorResponse(
                endpoint: $endpoint,
                status: 503,
                message: 'Unable to reach the remote TravelBooking ticket API.',
                exceptionMessage: $exception->getMessage(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->ticketErrorResponse(
                endpoint: $endpoint,
                status: 500,
                message: 'An unexpected error occurred while creating the ticket.',
                exceptionMessage: $exception->getMessage(),
            );
        }

        $data = $this->normalizePayload($response->json());

        if ($response->successful() && data_get($data, 'isExecute') !== false) {
            return [
                'error' => false,
                'resource' => 'tickets',
                'endpoint' => $url,
                'status' => $response->status(),
                'data' => $data,
            ];
        }

        if ($response->successful()) {
            return $this->ticketErrorResponse(
                endpoint: $endpoint,
                status: 422,
                message: is_string(data_get($data, 'message')) && data_get($data, 'message') !== ''
                    ? (string) data_get($data, 'message')
                    : 'The remote TravelBooking ticket API could not create the ticket.',
                response: $response,
            );
        }

        return $this->ticketErrorResponse(
            endpoint: $endpoint,
            status: $response->status(),
            message: $this->ticketMessageForStatus($response->status()),
            response: $response,
        );
    }

    /**
     * @param  array{seatinfo: array<int, array<string, mixed>>, paymentinfo: array<string, mixed>}  $payload
     * @return array<string, mixed>
     */
    public function createTripBooking(string $bearerToken, array $payload): array
    {
        $endpoint = '/api/booking';
        $url = $this->endpointUrl($endpoint);

        try {
            $this->logTravelBookingRequest(
                resource: 'trip_booking',
                method: 'POST',
                url: $url,
                token: $bearerToken,
                payload: $payload,
            );

            $response = $this->baseRequest($bearerToken)->post($url, $payload);
        } catch (ConnectionException $exception) {
            return $this->bookingErrorResponse(
                resource: 'trip_booking',
                endpoint: $endpoint,
                status: 503,
                message: 'Unable to reach the remote TravelBooking trip booking API.',
                exceptionMessage: $exception->getMessage(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->bookingErrorResponse(
                resource: 'trip_booking',
                endpoint: $endpoint,
                status: 500,
                message: 'An unexpected error occurred while creating the trip booking.',
                exceptionMessage: $exception->getMessage(),
            );
        }

        $data = $this->normalizePayload($response->json());

        if ($response->successful() && ! $this->normalizedResponseFailed($data)) {
            return [
                'error' => false,
                'resource' => 'trip_booking',
                'endpoint' => $url,
                'status' => $response->status(),
                'data' => $data,
            ];
        }

        return $this->bookingErrorResponse(
            resource: 'trip_booking',
            endpoint: $endpoint,
            status: $response->status(),
            message: $this->bookingMessageForStatus('trip_booking', $response->status(), $data),
            response: $response,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchHotelDetail(string $bearerToken, int $hotelId): array
    {
        $endpoint = '/api/hotel/'.$hotelId;
        $url = $this->endpointUrl($endpoint);

        try {
            $this->logTravelBookingRequest(
                resource: 'hotel_detail',
                method: 'GET',
                url: $url,
                token: $bearerToken,
            );

            $response = $this->baseRequest($bearerToken)->get($url);
        } catch (ConnectionException $exception) {
            return $this->bookingErrorResponse(
                resource: 'hotel_detail',
                endpoint: $endpoint,
                status: 503,
                message: 'Unable to reach the remote TravelBooking hotel detail API.',
                exceptionMessage: $exception->getMessage(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->bookingErrorResponse(
                resource: 'hotel_detail',
                endpoint: $endpoint,
                status: 500,
                message: 'An unexpected error occurred while loading hotel details.',
                exceptionMessage: $exception->getMessage(),
            );
        }

        $data = $this->normalizePayload($response->json());

        if ($response->successful() && ! $this->normalizedResponseFailed($data)) {
            return [
                'error' => false,
                'resource' => 'hotel_detail',
                'endpoint' => $url,
                'status' => $response->status(),
                'data' => $data,
            ];
        }

        return $this->bookingErrorResponse(
            resource: 'hotel_detail',
            endpoint: $endpoint,
            status: $response->status(),
            message: $this->bookingMessageForStatus('hotel_detail', $response->status(), $data),
            response: $response,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createHotelBooking(string $bearerToken, array $payload): array
    {
        $endpoint = '/api/hotel/booking';
        $url = $this->endpointUrl($endpoint);

        try {
            $this->logTravelBookingRequest(
                resource: 'hotel_booking',
                method: 'POST',
                url: $url,
                token: $bearerToken,
                payload: $payload,
            );

            $response = $this->baseRequest($bearerToken)->post($url, $payload);
        } catch (ConnectionException $exception) {
            return $this->bookingErrorResponse(
                resource: 'hotel_booking',
                endpoint: $endpoint,
                status: 503,
                message: 'Unable to reach the remote TravelBooking hotel booking API.',
                exceptionMessage: $exception->getMessage(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->bookingErrorResponse(
                resource: 'hotel_booking',
                endpoint: $endpoint,
                status: 500,
                message: 'An unexpected error occurred while creating the hotel booking.',
                exceptionMessage: $exception->getMessage(),
            );
        }

        $data = $this->normalizePayload($response->json());

        if ($response->successful() && ! $this->normalizedResponseFailed($data)) {
            return [
                'error' => false,
                'resource' => 'hotel_booking',
                'endpoint' => $url,
                'status' => $response->status(),
                'data' => $data,
            ];
        }

        return $this->bookingErrorResponse(
            resource: 'hotel_booking',
            endpoint: $endpoint,
            status: $response->status(),
            message: $this->bookingMessageForStatus('hotel_booking', $response->status(), $data),
            response: $response,
        );
    }

    /**
     * @param  array<int, string>  $resources
     * @return array<string, array<string, mixed>>
     */
    public function searchOverview(string $bearerToken, string $searchTerm, array $resources): array
    {
        $results = [];

        foreach ($this->resourceEndpoints($resources) as $resource => $endpoint) {
            try {
                $results[$resource] = $this->performRequest(
                    bearerToken: $bearerToken,
                    resource: $resource,
                    endpoint: $endpoint,
                    searchTerm: $searchTerm,
                );
            } catch (ConnectionException $exception) {
                $results[$resource] = $this->errorSection(
                    resource: $resource,
                    endpoint: $endpoint,
                    searchTerm: $searchTerm,
                    status: 503,
                    message: "Unable to reach the remote TravelBooking {$resource} API.",
                    exceptionMessage: $exception->getMessage(),
                );
            } catch (Throwable $exception) {
                report($exception);

                $results[$resource] = $this->errorSection(
                    resource: $resource,
                    endpoint: $endpoint,
                    searchTerm: $searchTerm,
                    status: 500,
                    message: "An unexpected error occurred while searching {$resource}.",
                    exceptionMessage: $exception->getMessage(),
                );
            }
        }

        return $results;
    }

    /**
     * @param  array<int, string>  $resources
     * @return array<string, string>
     */
    private function resourceEndpoints(array $resources): array
    {
        $all = [
            'trips' => '/api/trips',
            'packages' => '/api/packages',
            'hotels' => '/api/hotels',
        ];

        if ($resources === []) {
            return $all;
        }

        return array_intersect_key($all, array_flip($resources));
    }

    /**
     * @return array<string, mixed>
     */
    private function performRequest(string $bearerToken, string $resource, string $endpoint, string $searchTerm): array
    {
        $url = $this->endpointUrl($endpoint);
        $payload = ['location' => $searchTerm];

        $this->logTravelBookingRequest(
            resource: $resource,
            method: 'POST',
            url: $url,
            token: $bearerToken,
            payload: $payload,
        );

        $response = $this->baseRequest($bearerToken)
            ->post($url, $payload);

        if ($response->successful()) {
            return [
                'error' => false,
                'resource' => $resource,
                'endpoint' => $url,
                'query' => $searchTerm,
                'location' => $searchTerm,
                'status' => $response->status(),
                'data' => $this->filterPayloadForSearchTerm($this->normalizePayload($response->json()), $searchTerm),
            ];
        }

        return $this->errorSection(
            resource: $resource,
            endpoint: $endpoint,
            searchTerm: $searchTerm,
            status: $response->status(),
            message: $this->messageForStatus($resource, $response->status()),
            response: $response,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function errorSection(
        string $resource,
        string $endpoint,
        string $searchTerm,
        int $status,
        string $message,
        ?Response $response = null,
        ?string $exceptionMessage = null,
    ): array {
        $section = [
            'error' => true,
            'resource' => $resource,
            'endpoint' => $this->endpointUrl($endpoint),
            'query' => $searchTerm,
            'location' => $searchTerm,
            'status' => $status,
            'message' => $message,
        ];

        $details = $this->extractDetails($response, $exceptionMessage);

        if ($details !== null) {
            $section['details'] = $details;
        }

        return $section;
    }

    private function messageForStatus(string $resource, int $status): string
    {
        return match ($status) {
            401 => "The remote TravelBooking {$resource} API rejected the forwarded bearer token.",
            403 => "The authenticated user is not allowed to access {$resource}.",
            404 => "The remote TravelBooking {$resource} API endpoint was not found.",
            422 => "The remote TravelBooking {$resource} API could not process the supplied search term.",
            default => "The remote TravelBooking {$resource} API request failed with HTTP {$status}.",
        };
    }

    private function ticketMessageForStatus(int $status): string
    {
        return match ($status) {
            401 => 'The remote TravelBooking ticket API rejected the forwarded bearer token.',
            403 => 'The authenticated user is not allowed to create tickets.',
            404 => 'The remote TravelBooking ticket API endpoint was not found.',
            422 => 'The remote TravelBooking ticket API could not process the supplied ticket details.',
            default => "The remote TravelBooking ticket API request failed with HTTP {$status}.",
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function bookingMessageForStatus(string $resource, int $status, array $data): string
    {
        $providerMessage = data_get($data, 'message');

        if (is_string($providerMessage) && trim($providerMessage) !== '') {
            return trim($providerMessage);
        }

        return match ($status) {
            401 => "The remote TravelBooking {$resource} API rejected the forwarded bearer token.",
            403 => "The authenticated user is not allowed to access the {$resource} action.",
            404 => "The remote TravelBooking {$resource} API endpoint was not found.",
            422 => "The remote TravelBooking {$resource} API could not process the supplied booking details.",
            default => "The remote TravelBooking {$resource} API request failed with HTTP {$status}.",
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractDetails(?Response $response, ?string $exceptionMessage): ?array
    {
        $details = [];

        if ($response !== null) {
            $decoded = $response->json();

            if (is_array($decoded) && $decoded !== []) {
                $details['response'] = $decoded;
            } elseif ($response->body() !== '') {
                $details['response'] = [
                    'body' => Str::limit($response->body(), 1000),
                ];
            }
        }

        if ($exceptionMessage !== null && $exceptionMessage !== '') {
            $details['exception'] = $exceptionMessage;
        }

        return $details === [] ? null : $details;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            if ($payload === []) {
                return ['data' => []];
            }

            return array_is_list($payload) ? ['data' => $payload] : $payload;
        }

        if ($payload === null) {
            return ['data' => null];
        }

        return ['data' => $payload];
    }

    /**
     * @return array<string, mixed>
     */
    private function ticketErrorResponse(
        string $endpoint,
        int $status,
        string $message,
        ?Response $response = null,
        ?string $exceptionMessage = null,
    ): array {
        $result = [
            'error' => true,
            'resource' => 'tickets',
            'endpoint' => $this->endpointUrl($endpoint),
            'status' => $status,
            'message' => $message,
        ];

        $details = $this->extractDetails($response, $exceptionMessage);

        if ($details !== null) {
            $result['details'] = $details;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function normalizedResponseFailed(array $data): bool
    {
        $status = $data['status'] ?? $data['isExecute'] ?? $data['isExecture'] ?? null;

        if (is_bool($status)) {
            return $status === false;
        }

        if (is_string($status)) {
            return in_array(Str::lower(trim($status)), ['failed', 'false', 'error'], true);
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function bookingErrorResponse(
        string $resource,
        string $endpoint,
        int $status,
        string $message,
        ?Response $response = null,
        ?string $exceptionMessage = null,
    ): array {
        $result = [
            'error' => true,
            'resource' => $resource,
            'endpoint' => $this->endpointUrl($endpoint),
            'status' => $status,
            'message' => $message,
        ];

        $details = $this->extractDetails($response, $exceptionMessage);

        if ($details !== null) {
            $result['details'] = $details;
        }

        return $result;
    }

    private function baseRequest(string $token): PendingRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->withToken($token)
            ->connectTimeout((int) config('services.travelbooking.connect_timeout', 5))
            ->timeout((int) config('services.travelbooking.timeout', 20));

        $headers = array_filter([
            'Referer' => config('services.travelbooking.referer'),
            'Origin' => config('services.travelbooking.origin'),
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');

        if ($headers !== []) {
            $request = $request->withHeaders($headers);
        }

        if (! (bool) config('services.travelbooking.verify', true)) {
            $request = $request->withoutVerifying();
        }

        return $request;
    }

    private function endpointUrl(string $endpoint): string
    {
        return rtrim((string) config('services.travelbooking.base_url'), '/').$endpoint;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function filterPayloadForSearchTerm(array $payload, string $searchTerm): array
    {
        $items = $payload['data'] ?? null;

        if (! is_array($items) || ! array_is_list($items)) {
            return $payload;
        }

        $filtered = array_values(array_filter(
            $items,
            fn (mixed $item): bool => is_array($item) && $this->matchesSearchTerm($item, $searchTerm),
        ));

        $payload['data'] = $filtered;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function matchesSearchTerm(array $item, string $searchTerm): bool
    {
        $normalizedNeedle = $this->normalizeSearchText($searchTerm);

        if ($normalizedNeedle === '') {
            return true;
        }

        $haystacks = array_filter([
            $item['location'] ?? null,
            $item['city'] ?? null,
            $item['country'] ?? null,
            $item['destination'] ?? null,
            $item['name'] ?? null,
            $item['hotel_name'] ?? null,
            $item['package_name'] ?? null,
            $item['trip_name'] ?? null,
            $item['title'] ?? null,
            $item['address'] ?? null,
            $item['description'] ?? null,
        ], static fn (mixed $value): bool => is_scalar($value) && (string) $value !== '');

        foreach ($haystacks as $haystack) {
            $normalizedHaystack = $this->normalizeSearchText((string) $haystack);

            if ($normalizedHaystack !== '' && str_contains($normalizedHaystack, $normalizedNeedle)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeSearchText(string $value): string
    {
        $normalized = Str::lower($value);
        $normalized = str_replace(["'", '`'], '', $normalized);
        $normalized = preg_replace('/\bbazer\b/u', 'bazar', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^a-z0-9]+/u', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }
}
