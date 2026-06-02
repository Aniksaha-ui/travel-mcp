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
     * @param  array<int, string>  $resources
     * @return array<string, array<string, mixed>>
     */
    public function searchOverview(string $bearerToken, string $location, array $resources): array
    {
        $results = [];

        foreach ($this->resourceEndpoints($resources) as $resource => $endpoint) {
            try {
                $results[$resource] = $this->performRequest(
                    bearerToken: $bearerToken,
                    resource: $resource,
                    endpoint: $endpoint,
                    location: $location,
                );
            } catch (ConnectionException $exception) {
                $results[$resource] = $this->errorSection(
                    resource: $resource,
                    endpoint: $endpoint,
                    location: $location,
                    status: 503,
                    message: "Unable to reach the remote TravelBooking {$resource} API.",
                    exceptionMessage: $exception->getMessage(),
                );
            } catch (Throwable $exception) {
                report($exception);

                $results[$resource] = $this->errorSection(
                    resource: $resource,
                    endpoint: $endpoint,
                    location: $location,
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
    private function performRequest(string $bearerToken, string $resource, string $endpoint, string $location): array
    {
        $url = $this->endpointUrl($endpoint);
        $payload = ['location' => $location];

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
                'location' => $location,
                'status' => $response->status(),
                'data' => $this->filterPayloadForLocation($this->normalizePayload($response->json()), $location),
            ];
        }

        return $this->errorSection(
            resource: $resource,
            endpoint: $endpoint,
            location: $location,
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
        string $location,
        int $status,
        string $message,
        ?Response $response = null,
        ?string $exceptionMessage = null,
    ): array {
        $section = [
            'error' => true,
            'resource' => $resource,
            'endpoint' => $this->endpointUrl($endpoint),
            'location' => $location,
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
            422 => "The remote TravelBooking {$resource} API could not process the supplied location.",
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
    private function filterPayloadForLocation(array $payload, string $location): array
    {
        $items = $payload['data'] ?? null;

        if (! is_array($items) || ! array_is_list($items)) {
            return $payload;
        }

        $filtered = array_values(array_filter(
            $items,
            fn (mixed $item): bool => is_array($item) && $this->matchesLocation($item, $location),
        ));

        $payload['data'] = $filtered;

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function matchesLocation(array $item, string $location): bool
    {
        $normalizedNeedle = $this->normalizeLocationText($location);

        if ($normalizedNeedle === '') {
            return true;
        }

        $haystacks = array_filter([
            $item['location'] ?? null,
            $item['city'] ?? null,
            $item['country'] ?? null,
            $item['destination'] ?? null,
            $item['name'] ?? null,
            $item['title'] ?? null,
            $item['description'] ?? null,
        ], static fn (mixed $value): bool => is_scalar($value) && (string) $value !== '');

        foreach ($haystacks as $haystack) {
            $normalizedHaystack = $this->normalizeLocationText((string) $haystack);

            if ($normalizedHaystack !== '' && str_contains($normalizedHaystack, $normalizedNeedle)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeLocationText(string $value): string
    {
        $normalized = Str::lower($value);
        $normalized = str_replace(["'", '`'], '', $normalized);
        $normalized = preg_replace('/\bbazer\b/u', 'bazar', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^a-z0-9]+/u', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }
}
