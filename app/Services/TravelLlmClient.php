<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TravelLlmClient
{
    public function chat(string $systemPrompt, string $userPrompt, ?float $temperature = null): string
    {
        $baseUrl = rtrim((string) config('services.travel_intent_llm.base_url'), '/');
        $apiKey = trim((string) config('services.travel_intent_llm.api_key'));
        $model = trim((string) config('services.travel_intent_llm.model'));

        if ($baseUrl === '' || $apiKey === '' || $model === '') {
            throw new RuntimeException(
                'Travel intent LLM is not configured. Please set the travel intent LLM service credentials.',
                500,
            );
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->withToken($apiKey)
                ->connectTimeout((int) config('services.travel_intent_llm.connect_timeout', 5))
                ->timeout((int) config('services.travel_intent_llm.timeout', 20))
                ->post($baseUrl.'/chat/completions', [
                    'model' => $model,
                    'temperature' => $temperature ?? (float) config('services.travel_intent_llm.temperature', 0),
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => $systemPrompt,
                        ],
                        [
                            'role' => 'user',
                            'content' => $userPrompt,
                        ],
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Unable to reach the configured travel intent LLM.', 503, $exception);
        }

        if (! $response->successful()) {
            $status = $response->status();
            $providerMessage = $response->json('error.message');

            if (! is_string($providerMessage) || trim($providerMessage) === '') {
                $providerMessage = 'The configured travel intent LLM request failed.';
            }

            throw new RuntimeException(trim($providerMessage), $status);
        }

        $content = data_get($response->json(), 'choices.0.message.content');
        $text = $this->extractTextContent($content);

        if ($text === null) {
            throw new RuntimeException('Travel intent LLM returned an empty response.', 502);
        }

        return $text;
    }

    private function extractTextContent(mixed $content): ?string
    {
        if (is_string($content)) {
            $trimmed = trim($content);

            return $trimmed !== '' ? $trimmed : null;
        }

        if (! is_array($content)) {
            return null;
        }

        $parts = [];

        foreach ($content as $part) {
            if (is_string($part) && trim($part) !== '') {
                $parts[] = trim($part);

                continue;
            }

            if (! is_array($part)) {
                continue;
            }

            $text = $part['text'] ?? $part['content'] ?? null;

            if (is_string($text) && trim($text) !== '') {
                $parts[] = trim($text);
            }
        }

        return $parts === [] ? null : implode("\n", $parts);
    }
}
