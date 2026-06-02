<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use Illuminate\Support\Facades\Log;

trait LogsTravelBookingRequests
{
    /**
     * @param  array<string, mixed>  $payload
     */
    protected function logTravelBookingRequest(
        string $resource,
        string $method,
        string $url,
        string $token,
        array $payload = [],
    ): void {
        Log::info('TravelBooking API request sent.', [
            'resource' => $resource,
            'method' => strtoupper($method),
            'url' => $url,
            'curl' => $this->buildTravelBookingCurlCommand($method, $url, $token, $payload),
            'mcp_session_id' => request()->header('MCP-Session-Id'),
            'user_id' => request()->user()?->getAuthIdentifier(),
            'ip' => request()->ip(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function buildTravelBookingCurlCommand(
        string $method,
        string $url,
        string $token,
        array $payload = [],
    ): string {
        $parts = [
            'curl',
            '-X',
            strtoupper($method),
            escapeshellarg($url),
        ];

        foreach ($this->travelBookingCurlHeaders($token, $payload !== []) as $name => $value) {
            $parts[] = '-H';
            $parts[] = escapeshellarg("{$name}: {$value}");
        }

        if ($payload !== []) {
            $parts[] = '--data-raw';
            $parts[] = escapeshellarg((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }

        if (! (bool) config('services.travelbooking.verify', true)) {
            $parts[] = '--insecure';
        }

        return implode(' ', $parts);
    }

    /**
     * @return array<string, string>
     */
    protected function travelBookingCurlHeaders(string $token, bool $hasPayload = true): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$this->maskTravelBookingToken($token),
        ];

        if ($hasPayload) {
            $headers['Content-Type'] = 'application/json';
        }

        $referer = config('services.travelbooking.referer');
        $origin = config('services.travelbooking.origin');

        if (is_string($referer) && $referer !== '') {
            $headers['Referer'] = $referer;
        }

        if (is_string($origin) && $origin !== '') {
            $headers['Origin'] = $origin;
        }

        return $headers;
    }

    protected function maskTravelBookingToken(string $token): string
    {
        $length = strlen($token);

        if ($length <= 10) {
            return str_repeat('*', max($length, 1));
        }

        return substr($token, 0, 6).str_repeat('*', max($length - 10, 1)).substr($token, -4);
    }
}
