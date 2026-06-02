<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TravelChatRequest;
use App\Services\TravelChatService;
use Illuminate\Http\JsonResponse;

class TravelChatController extends Controller
{
    public function __construct(
        private readonly TravelChatService $travelChatService,
    ) {
    }

    public function __invoke(TravelChatRequest $request): JsonResponse
    {
        $token = $request->bearerToken();

        if (! is_string($token) || $token === '') {
            return response()->json([
                'message' => 'Missing bearer token.',
            ], 401);
        }

        $response = $this->travelChatService->handle(
            message: $request->string('message')->toString(),
            bearerToken: $token,
        );

        return response()->json($response, $response['status']);
    }
}
