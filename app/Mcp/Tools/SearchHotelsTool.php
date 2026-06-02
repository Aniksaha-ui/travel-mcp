<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\LogsTravelBookingRequests;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Description('Searches authenticated hotel results for a specific location using the remote TravelBooking hotels API.')]
class SearchHotelsTool extends Tool
{
    use LogsTravelBookingRequests;

    private const ENDPOINT = '/api/hotels';

    private const RESOURCE = 'hotels';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): ResponseFactory
    {
        $startedAt = microtime(true);
        $location = trim((string) $request->get('location'));

        if ($location === '') {
            return $this->errorResponse(
                message: 'A non-empty location is required to search hotels.',
                status: 422,
            );
        }

        if (Str::length($location) > 150) {
            return $this->errorResponse(
                message: 'The provided hotel location is too long. Please keep it under 150 characters.',
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

        try {
            $response = $this->performRequest($token, $location);

            if ($response->successful()) {
                return $this->successResponse(
                    payload: $response->json(),
                    location: $location,
                    status: $response->status(),
                    durationMs: $this->durationMs($startedAt),
                );
            }

            return $this->errorResponse(
                message: $this->messageForStatus($response->status()),
                status: $response->status(),
                location: $location,
                response: $response,
            );
        } catch (ConnectionException $exception) {
            return $this->errorResponse(
                message: 'Unable to reach the remote TravelBooking hotels API.',
                status: 503,
                location: $location,
                exceptionMessage: $exception->getMessage(),
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->errorResponse(
                message: 'An unexpected error occurred while searching hotels.',
                status: 500,
                location: $location,
                exceptionMessage: $exception->getMessage(),
            );
        }
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
                ->description('The destination, city, country, or place to search hotels for.')
                ->required(),
        ];
    }

    private function performRequest(string $token, string $location): HttpResponse
    {
        $this->logTravelBookingRequest(
            resource: self::RESOURCE,
            method: 'POST',
            url: $this->endpointUrl(),
            token: $token,
            payload: ['location' => $location],
        );

        return $this->baseRequest($token)
            ->post($this->endpointUrl(), [
                'location' => $location,
            ]);
    }

    private function successResponse(mixed $payload, string $location, int $status, int $durationMs): ResponseFactory
    {
        $normalizedPayload = $this->normalizePayload($payload);

        Log::info('MCP hotel data retrieved.', [
            'resource' => self::RESOURCE,
            'endpoint' => $this->endpointUrl(),
            'location' => $location,
            'http_status' => $status,
            'result_count' => $this->resultCount($payload),
            'duration_ms' => $durationMs,
            'mcp_session_id' => request()->header('MCP-Session-Id'),
            'user_id' => request()->user()?->getAuthIdentifier(),
            'ip' => request()->ip(),
        ]);

        return Response::make(
            Response::text("Hotel search completed successfully for {$location}.")
        )->withStructuredContent($normalizedPayload)
            ->withMeta([
                'resource' => self::RESOURCE,
                'endpoint' => $this->endpointUrl(),
                'location' => $location,
                'http_status' => $status,
                'duration_ms' => $durationMs,
            ]);
    }

    private function errorResponse(
        string $message,
        int $status,
        ?string $location = null,
        ?HttpResponse $response = null,
        ?string $exceptionMessage = null,
    ): ResponseFactory {
        $structuredContent = [
            'error' => true,
            'resource' => self::RESOURCE,
            'endpoint' => $this->endpointUrl(),
            'status' => $status,
            'message' => $message,
        ];

        if ($location !== null && $location !== '') {
            $structuredContent['location'] = $location;
        }

        $details = $this->extractDetails($response, $exceptionMessage);

        if ($details !== null) {
            $structuredContent['details'] = $details;
        }

        return Response::make(
            Response::error($message)
        )->withStructuredContent($structuredContent)
            ->withMeta([
                'resource' => self::RESOURCE,
                'endpoint' => $this->endpointUrl(),
                'http_status' => $status,
            ]);
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

    private function messageForStatus(int $status): string
    {
        return match ($status) {
            401 => 'The remote TravelBooking hotels API rejected the forwarded bearer token.',
            403 => 'The authenticated user is not allowed to access hotels.',
            404 => 'The remote TravelBooking hotels API endpoint was not found.',
            422 => 'The remote TravelBooking hotels API could not process the supplied location.',
            default => "The remote TravelBooking hotels API request failed with HTTP {$status}.",
        };
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function resultCount(mixed $payload): ?int
    {
        if (! is_array($payload)) {
            return null;
        }

        if (array_is_list($payload)) {
            return count($payload);
        }

        $data = $payload['data'] ?? null;

        if (is_array($data) && array_is_list($data)) {
            return count($data);
        }

        return null;
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

    private function endpointUrl(): string
    {
        return rtrim((string) config('services.travelbooking.base_url'), '/').self::ENDPOINT;
    }
}
