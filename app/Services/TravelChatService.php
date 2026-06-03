<?php

declare(strict_types=1);

namespace App\Services;

class TravelChatService
{
    public function __construct(
        private readonly TravelQueryParser $travelQueryParser,
        private readonly TravelBookingApiClient $travelBookingApiClient,
        private readonly TravelChatHtmlRenderer $travelChatHtmlRenderer,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(string $message, string $bearerToken): array
    {
        $parsed = $this->travelQueryParser->parse($message);
        $location = $parsed['location'];
        $resources = $parsed['resources'];

        if (! is_string($location) || $location === '') {
            return [
                'status' => 422,
                'message' => 'Unable to determine the travel location from the provided message.',
                'input' => [
                    'message' => $message,
                ],
                'parsed' => [
                    'location' => null,
                    'resources' => $resources,
                ],
            ];
        }

        $overview = $this->travelBookingApiClient->searchOverview($bearerToken, $location, $resources);
        $html = $this->travelChatHtmlRenderer->render($location, $overview, $resources);
        $partialFailure = collect($overview)->contains(fn (array $section): bool => ($section['error'] ?? false) === true);
        $allFailed = collect($overview)->every(fn (array $section): bool => ($section['error'] ?? false) === true);

        return [
            'status' => $allFailed ? 502 : 200,
            'message' => $allFailed
                ? 'Unable to retrieve travel data from the upstream travel services.'
                : 'Travel chat response generated successfully.',
            'input' => [
                'message' => $message,
            ],
            'parsed' => [
                'location' => $location,
                'resources' => $resources,
            ],
            'partial_failure' => $partialFailure,
            'presentation_instruction' => $this->travelChatHtmlRenderer->presentationInstruction(),
            'html' => [
                'full' => $html['full'],
                'summary' => $html['summary'],
                'trips' => $html['trips'],
                'packages' => $html['packages'],
                'hotels' => $html['hotels'],
            ],
            'data' => $overview,
        ];
    }
}
