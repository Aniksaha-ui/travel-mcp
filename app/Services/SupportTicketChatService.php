<?php

declare(strict_types=1);

namespace App\Services;

class SupportTicketChatService
{
    public function __construct(
        private readonly SupportTicketChatParser $supportTicketChatParser,
        private readonly TravelBookingApiClient $travelBookingApiClient,
        private readonly SupportTicketChatHtmlRenderer $supportTicketChatHtmlRenderer,
    ) {
    }

    public function shouldHandle(string $message): bool
    {
        return $this->supportTicketChatParser->shouldHandle($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(string $message, string $bearerToken): array
    {
        $ticket = $this->supportTicketChatParser->parse($message);

        if ($ticket['needs_more_information']) {
            $html = $this->supportTicketChatHtmlRenderer->renderNeedsMoreInformation($ticket);

            return [
                'status' => 422,
                'action' => 'create_ticket',
                'message' => 'Please share the support issue in more detail so the ticket can be created.',
                'authentication' => [
                    'synced' => true,
                    'method' => 'forwarded_bearer_token',
                ],
                'input' => [
                    'message' => $message,
                ],
                'ticket' => [
                    'created' => false,
                    'needs_more_information' => true,
                    'payload' => [
                        'title' => $ticket['title'],
                        'description' => $ticket['description'],
                        'remarks' => $ticket['remarks'],
                    ],
                ],
                'html' => $html,
            ];
        }

        $payload = array_filter([
            'title' => $ticket['title'],
            'description' => $ticket['description'],
            'remarks' => $ticket['remarks'],
        ], static fn (mixed $value): bool => is_string($value) && $value !== '');

        $result = $this->travelBookingApiClient->createTicket($bearerToken, $payload);

        if (($result['error'] ?? true) === true) {
            $html = $this->supportTicketChatHtmlRenderer->renderFailure(
                $payload,
                (string) ($result['message'] ?? 'The ticket could not be created.'),
            );

            return [
                'status' => (int) ($result['status'] ?? 502),
                'action' => 'create_ticket',
                'message' => $result['message'] ?? 'The ticket could not be created.',
                'authentication' => [
                    'synced' => true,
                    'method' => 'forwarded_bearer_token',
                ],
                'input' => [
                    'message' => $message,
                ],
                'ticket' => [
                    'created' => false,
                    'payload' => $payload,
                    'endpoint' => $result['endpoint'] ?? null,
                    'upstream' => $result['details']['response'] ?? null,
                ],
                'html' => $html,
            ];
        }

        $upstreamMessage = $result['data']['message'] ?? 'Ticket created successfully.';
        $html = $this->supportTicketChatHtmlRenderer->renderCreated($payload, (string) $upstreamMessage);

        return [
            'status' => 200,
            'action' => 'create_ticket',
            'message' => $upstreamMessage,
            'authentication' => [
                'synced' => true,
                'method' => 'forwarded_bearer_token',
            ],
            'input' => [
                'message' => $message,
            ],
            'ticket' => [
                'created' => true,
                'payload' => $payload,
                'endpoint' => $result['endpoint'] ?? null,
                'upstream' => $result['data'] ?? null,
            ],
            'html' => $html,
        ];
    }
}
