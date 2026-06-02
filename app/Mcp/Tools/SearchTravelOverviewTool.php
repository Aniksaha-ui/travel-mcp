<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\LogsTravelBookingRequests;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Description('Fetches trips, packages, and hotels together for one location from the remote TravelBooking APIs.')]
class SearchTravelOverviewTool extends Tool
{
    use LogsTravelBookingRequests;

    private const RESOURCE = 'travel_overview';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): ResponseFactory
    {
        $location = trim((string) $request->get('location'));

        if ($location === '') {
            return $this->errorResponse(
                message: 'A non-empty location is required to fetch travel overview data.',
                status: 422,
            );
        }

        if (Str::length($location) > 150) {
            return $this->errorResponse(
                message: 'The provided travel overview location is too long. Please keep it under 150 characters.',
                status: 422,
                location: $location,
            );
        }

        $token = request()->bearerToken();

        if ($token === null || $token === '') {
            return $this->errorResponse(
                message: 'Missing bearer token on the incoming MCP request.',
                status: 401,
                location: $location,
            );
        }

        $sections = [];

        foreach ($this->resources() as $resource => $endpoint) {
            try {
                $response = $this->performRequest($token, $resource, $endpoint, $location);

                $sections[$resource] = $response->successful()
                    ? $this->successSection($resource, $endpoint, $location, $response)
                    : $this->errorSection(
                        resource: $resource,
                        endpoint: $endpoint,
                        location: $location,
                        status: $response->status(),
                        message: $this->messageForStatus($resource, $response->status()),
                        response: $response,
                    );
            } catch (ConnectionException $exception) {
                $sections[$resource] = $this->errorSection(
                    resource: $resource,
                    endpoint: $endpoint,
                    location: $location,
                    status: 503,
                    message: "Unable to reach the remote TravelBooking {$resource} API.",
                    exceptionMessage: $exception->getMessage(),
                );
            } catch (Throwable $exception) {
                report($exception);

                $sections[$resource] = $this->errorSection(
                    resource: $resource,
                    endpoint: $endpoint,
                    location: $location,
                    status: 500,
                    message: "An unexpected error occurred while searching {$resource}.",
                    exceptionMessage: $exception->getMessage(),
                );
            }
        }

        $hasAnySuccess = collect($sections)->contains(fn (array $section): bool => ($section['error'] ?? false) === false);
        $hasAnyError = collect($sections)->contains(fn (array $section): bool => ($section['error'] ?? false) === true);

        $structuredContent = [
            'error' => ! $hasAnySuccess,
            'resource' => self::RESOURCE,
            'location' => $location,
            'trips' => $sections['trips'] ?? null,
            'packages' => $sections['packages'] ?? null,
            'hotels' => $sections['hotels'] ?? null,
        ];

        if ($hasAnyError) {
            $structuredContent['partial_failure'] = $hasAnySuccess;
        }

        $responseText = $hasAnySuccess
            ? "Travel overview data fetched for {$location}."
            : "Unable to fetch travel overview data for {$location}.";

        $baseResponse = $hasAnySuccess
            ? Response::text($responseText)
            : Response::error($responseText);

        return Response::make($baseResponse)
            ->withStructuredContent($structuredContent)
            ->withMeta([
                'resource' => self::RESOURCE,
                'location' => $location,
                'partial_failure' => $hasAnyError && $hasAnySuccess,
            ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'location' => $schema->string()
                ->description('The destination, city, country, or place to fetch trips, packages, and hotels for.')
                ->required(),
        ];
    }

    private function performRequest(string $token, string $resource, string $endpoint, string $location): HttpResponse
    {
        $payload = ['location' => $location];

        $this->logTravelBookingRequest(
            resource: $resource,
            method: 'POST',
            url: $this->endpointUrl($endpoint),
            token: $token,
            payload: $payload,
        );

        return $this->baseRequest($token)
            ->post($this->endpointUrl($endpoint), $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function successSection(string $resource, string $endpoint, string $location, HttpResponse $response): array
    {
        return [
            'error' => false,
            'resource' => $resource,
            'endpoint' => $this->endpointUrl($endpoint),
            'location' => $location,
            'status' => $response->status(),
            'data' => $this->normalizePayload($response->json()),
        ];
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
        ?HttpResponse $response = null,
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

    private function errorResponse(string $message, int $status, ?string $location = null): ResponseFactory
    {
        $structuredContent = [
            'error' => true,
            'resource' => self::RESOURCE,
            'status' => $status,
            'message' => $message,
        ];

        if ($location !== null && $location !== '') {
            $structuredContent['location'] = $location;
        }

        return Response::make(
            Response::error($message)
        )->withStructuredContent($structuredContent)
            ->withMeta([
                'resource' => self::RESOURCE,
                'http_status' => $status,
            ]);
    }

    /**
     * @return array<string, string>
     */
    private function resources(): array
    {
        return [
            'trips' => '/api/trips',
            'packages' => '/api/packages',
            'hotels' => '/api/hotels',
        ];
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
     * @return array<string, mixed>|null
     */
    private function extractDetails(?HttpResponse $response, ?string $exceptionMessage): ?array
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
}
