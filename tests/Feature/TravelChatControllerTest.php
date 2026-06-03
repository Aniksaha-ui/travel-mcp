<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TravelChatControllerTest extends TestCase
{
    public function test_it_returns_travel_data_and_html_for_a_customer_message(): void
    {
        config()->set('services.travel_intent_llm', [
            'base_url' => 'https://llm.example/v1',
            'api_key' => 'test-key',
            'model' => 'gpt-4o-mini',
            'connect_timeout' => 5,
            'timeout' => 20,
            'temperature' => 0,
        ]);

        Http::fake([
            'https://llm.example/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => json_encode([
                                'location' => "Cox's Bazar",
                                'resources' => ['trips', 'packages', 'hotels'],
                            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ], 200),
            'https://travelbooking.infinitycodehubltd.com/public/api/trips*' => Http::response([
                'data' => [
                    [
                        'name' => 'Cox Trip',
                        'location' => "Cox's Bazer",
                        'status' => 1,
                        'price' => '109999.00',
                        'departure_time' => '2026-06-30 00:00:00',
                        'arrival_time' => '2026-06-30 00:00:00',
                        'origin' => 'Ocean Area',
                        'destination' => "Cox's Bazar",
                        'route_name' => "Dhaka-Cox's Bazar",
                        'vehicle_type' => 'flight',
                        'vehicle_name' => 'Nobo Air',
                        'image' => 'travel/example.jpg',
                    ],
                ],
            ], 200),
            'https://travelbooking.infinitycodehubltd.com/public/api/packages*' => Http::response([
                'data' => [
                    ['name' => 'Cox Package', 'location' => "Cox's Bazer"],
                ],
            ], 200),
            'https://travelbooking.infinitycodehubltd.com/public/api/hotels*' => Http::response([
                'data' => [
                    ['name' => 'Cox Hotel', 'location' => "Cox's Bazer"],
                ],
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer test-token')
            ->postJson('/api/chat', [
                'message' => "Give all information about Cox's Bazar trip, package and hotel",
            ]);

        $fullHtml = (string) $response->json('html.full');
        $tripsHtml = (string) $response->json('html.trips');

        $response->assertOk()
            ->assertJsonPath('parsed.location', "Cox's Bazar")
            ->assertJsonPath('data.trips.error', false)
            ->assertJsonPath('data.packages.error', false)
            ->assertJsonPath('data.hotels.error', false)
            ->assertJsonPath('data.trips.endpoint', 'https://travelbooking.infinitycodehubltd.com/public/api/trips')
            ->assertJsonPath('presentation_instruction', 'Present travel results as attractive, professional HTML. Start with a short natural-language summary like "There are 5 trips available for Cox\'s Bazar", then show a quick comparison table when useful, followed by numbered sections such as "Trip 1" or "Hotel 2". Keep the tone human and polished, highlight important details like price, status, rating, and description, and never dump raw JSON.')
            ->assertJsonStructure([
                'status',
                'message',
                'input' => ['message'],
                'parsed' => ['location', 'resources'],
                'partial_failure',
                'presentation_instruction',
                'html' => ['full', 'summary', 'trips', 'packages', 'hotels'],
                'data' => ['trips', 'packages', 'hotels'],
            ]);

        $this->assertStringContainsString('travel-chat-response', $fullHtml);
        $this->assertStringContainsString('Travel Overview', $fullHtml);
        $this->assertStringContainsString('Trip 1', $fullHtml);
        $this->assertStringContainsString('<article', $tripsHtml);
        $this->assertStringContainsString('<table', $tripsHtml);
        $this->assertStringContainsString('Cox Trip', $tripsHtml);
        $this->assertStringContainsString('There is 1 trip available for Cox&#039;s Bazar.', $tripsHtml);
        $this->assertStringContainsString('Trip 1', $tripsHtml);
        $this->assertStringContainsString('Available', $tripsHtml);
        $this->assertStringContainsString('109,999.00', $tripsHtml);
        $this->assertStringContainsString('30 Jun 2026, 12:00 AM', $tripsHtml);
        $this->assertStringContainsString('Ocean Area to Cox&#039;s Bazar via Dhaka-Cox&#039;s Bazar', $tripsHtml);
        $this->assertStringNotContainsString('travel/example.jpg', $tripsHtml);
        $this->assertStringNotContainsString('<pre>', $tripsHtml);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://llm.example/v1/chat/completions'
            && $request['model'] === 'gpt-4o-mini'
            && data_get($request->data(), 'messages.1.content') === "Give all information about Cox's Bazar trip, package and hotel");

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://travelbooking.infinitycodehubltd.com/public/api/trips'
            && $request->data() === ['location' => "Cox's Bazar"]);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://travelbooking.infinitycodehubltd.com/public/api/packages'
            && $request->data() === ['location' => "Cox's Bazar"]);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://travelbooking.infinitycodehubltd.com/public/api/hotels'
            && $request->data() === ['location' => "Cox's Bazar"]);
    }

    public function test_it_uses_llm_to_extract_misspelled_location_and_requested_resource_types(): void
    {
        config()->set('services.travel_intent_llm', [
            'base_url' => 'https://llm.example/v1',
            'api_key' => 'test-key',
            'model' => 'gpt-4o-mini',
            'connect_timeout' => 5,
            'timeout' => 20,
            'temperature' => 0,
        ]);

        Http::fake([
            'https://llm.example/v1/chat/completions' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => '{"location":"Cox\'s Bazar","resources":["trips"]}',
                        ],
                    ],
                ],
            ], 200),
            'https://travelbooking.infinitycodehubltd.com/public/api/trips*' => Http::response([
                'data' => [
                    [
                        'name' => 'Beach Flight',
                        'destination' => "Cox's Bazar",
                        'status' => 1,
                        'price' => '13300',
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer test-token')
            ->postJson('/api/chat', [
                'message' => "fetch all information of cox's bazer trips",
            ]);

        $response->assertOk()
            ->assertJsonPath('parsed.location', "Cox's Bazar")
            ->assertJsonPath('data.trips.error', false);

        $this->assertSame(['trips'], $response->json('parsed.resources'));
        $this->assertSame(['trips'], array_keys($response->json('data')));
        $this->assertStringContainsString('Beach Flight', (string) $response->json('html.trips'));

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://llm.example/v1/chat/completions'
            && data_get($request->data(), 'messages.1.content') === "fetch all information of cox's bazer trips");

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://travelbooking.infinitycodehubltd.com/public/api/trips'
            && $request->data() === ['location' => "Cox's Bazar"]);

        Http::assertSentCount(2);
    }

    public function test_it_returns_upstream_llm_status_and_message_when_intent_extraction_fails(): void
    {
        config()->set('services.travel_intent_llm', [
            'base_url' => 'https://llm.example/v1',
            'api_key' => 'test-key',
            'model' => 'gpt-4o-mini',
            'connect_timeout' => 5,
            'timeout' => 20,
            'temperature' => 0,
        ]);

        Http::fake([
            'https://llm.example/v1/chat/completions' => Http::response([
                'error' => [
                    'message' => 'You exceeded your current quota, please check your plan and billing details.',
                ],
            ], 429),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer test-token')
            ->postJson('/api/chat', [
                'message' => "fetch all information of cox's bazer trips",
            ]);

        $response->assertStatus(429)
            ->assertJson([
                'status' => 429,
                'message' => 'You exceeded your current quota, please check your plan and billing details.',
                'input' => [
                    'message' => "fetch all information of cox's bazer trips",
                ],
            ]);
    }
}
