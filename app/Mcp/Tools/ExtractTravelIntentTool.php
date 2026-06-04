<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\TravelQueryParser;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Throwable;

#[Description('Uses the configured LLM to extract a normalized travel search term, optional destination, requested resource types, and question focus from a natural-language customer message.')]
class ExtractTravelIntentTool extends Tool
{
    public function __construct(
        private readonly TravelQueryParser $travelQueryParser,
    ) {
    }

    public function handle(Request $request): ResponseFactory
    {
        $message = trim((string) $request->get('message'));

        if ($message === '') {
            return Response::make(
                Response::error('A non-empty message is required to extract travel intent.')
            )->withStructuredContent([
                'error' => true,
                'message' => 'A non-empty message is required to extract travel intent.',
                'status' => 422,
            ]);
        }

        try {
            $parsed = $this->travelQueryParser->parse($message);
        } catch (Throwable $exception) {
            report($exception);

            return Response::make(
                Response::error('Unable to analyze the travel request with the configured LLM.')
            )->withStructuredContent([
                'error' => true,
                'message' => 'Unable to analyze the travel request with the configured LLM.',
                'status' => 502,
            ]);
        }

        return Response::make(
            Response::text('Travel intent extracted successfully.')
        )->withStructuredContent([
            'error' => false,
            'search_term' => $parsed['search_term'],
            'location' => $parsed['location'],
            'resources' => $parsed['resources'],
            'question_focus' => $parsed['question_focus'],
        ])->withMeta([
            'message' => $message,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'message' => $schema->string()
                ->description('The natural-language customer message to analyze for a travel search term, optional destination, requested travel resource types, and the main question focus.')
                ->required(),
        ];
    }
}
