<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TravelChatControllerTest extends TestCase
{
    public function test_it_returns_travel_data_and_html_for_a_customer_message(): void
    {
        Http::fake([
            'https://travelbooking.infinitycodehubltd.com/public/api/trips*' => Http::response([
                'data' => [
                    ['name' => 'Cox Trip', 'location' => "Cox's Bazer"],
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

        $tripsHtml = (string) $response->json('html.trips');

        $response->assertOk()
            ->assertJsonPath('parsed.location', "Cox's Bazar")
            ->assertJsonPath('data.trips.error', false)
            ->assertJsonPath('data.packages.error', false)
            ->assertJsonPath('data.hotels.error', false)
            ->assertJsonPath('data.trips.endpoint', 'https://travelbooking.infinitycodehubltd.com/public/api/trips')
            ->assertJsonStructure([
                'status',
                'message',
                'input' => ['message'],
                'parsed' => ['location', 'resources'],
                'partial_failure',
                'html' => ['summary', 'trips', 'packages', 'hotels'],
                'data' => ['trips', 'packages', 'hotels'],
            ]);

        $this->assertStringContainsString('<article', $tripsHtml);
        $this->assertStringContainsString('Cox Trip', $tripsHtml);
        $this->assertStringNotContainsString('<pre>', $tripsHtml);

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
}
