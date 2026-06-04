<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;

class TravelChatService
{
    public function __construct(
        private readonly SupportTicketChatService $supportTicketChatService,
        private readonly TravelQueryParser $travelQueryParser,
        private readonly TravelBookingApiClient $travelBookingApiClient,
        private readonly TravelChatLlmResponseGenerator $travelChatLlmResponseGenerator,
        private readonly TravelChatHtmlRenderer $travelChatHtmlRenderer,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(string $message, string $bearerToken): array
    {
        if ($this->supportTicketChatService->shouldHandle($message)) {
            return $this->supportTicketChatService->handle($message, $bearerToken);
        }

        try {
            $parsed = $this->travelQueryParser->parse($message);
        } catch (Throwable $exception) {
            report($exception);
            $status = is_int($exception->getCode()) && $exception->getCode() >= 400 && $exception->getCode() <= 599
                ? $exception->getCode()
                : 502;

            return [
                'status' => $status,
                'action' => 'travel_search',
                'message' => $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : 'Unable to analyze the travel request with the configured LLM.',
                'input' => [
                    'message' => $message,
                ],
            ];
        }

        $searchTerm = $parsed['search_term'] ?? null;
        $location = $parsed['location'];
        $resources = $parsed['resources'];
        $questionFocus = $parsed['question_focus'] ?? 'general';
        $displayLabel = is_string($location) && $location !== ''
            ? $location
            : (is_string($searchTerm) ? $searchTerm : null);

        if (! is_string($searchTerm) || $searchTerm === '') {
            return [
                'status' => 422,
                'action' => 'travel_search',
                'message' => 'Unable to determine the travel search term from the provided message.',
                'input' => [
                    'message' => $message,
                ],
                'parsed' => [
                    'search_term' => null,
                    'location' => null,
                    'resources' => $resources,
                    'question_focus' => $questionFocus,
                ],
            ];
        }

        $overview = $this->travelBookingApiClient->searchOverview($bearerToken, $searchTerm, $resources);
        $html = $this->travelChatLlmResponseGenerator->generate(
            $message,
            [
                'search_term' => $searchTerm,
                'location' => is_string($location) && $location !== '' ? $location : null,
                'display_label' => $displayLabel ?? $searchTerm,
                'question_focus' => $questionFocus,
            ],
            $overview,
            $resources,
        );
        $partialFailure = collect($overview)->contains(fn (array $section): bool => ($section['error'] ?? false) === true);
        $allFailed = collect($overview)->every(fn (array $section): bool => ($section['error'] ?? false) === true);

        return [
            'status' => $allFailed ? 502 : 200,
            'action' => 'travel_search',
            'message' => $allFailed
                ? 'Unable to retrieve travel data from the upstream travel services.'
                : 'Travel chat response generated successfully.',
            'input' => [
                'message' => $message,
            ],
            'parsed' => [
                'search_term' => $searchTerm,
                'location' => is_string($location) && $location !== '' ? $location : null,
                'resources' => $resources,
                'question_focus' => $questionFocus,
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
