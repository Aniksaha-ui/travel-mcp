<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TravelChatControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.travel_chat.full_html_only', false);
    }

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

        $llmHtml = [
            'full' => '<section class="llm-full"><h2>Cox\'s Bazar Travel Plan</h2><p>There are 3 travel resources ready for you.</p></section>',
            'summary' => '<section class="llm-summary"><p>We found trips, packages, and hotels for Cox\'s Bazar.</p></section>',
            'trips' => '<section class="llm-trips"><h3>Trips</h3><p>Trip 1: Cox Trip</p></section>',
            'packages' => '<section class="llm-packages"><h3>Packages</h3><p>Package 1: Cox Package</p></section>',
            'hotels' => '<section class="llm-hotels"><h3>Hotels</h3><p>Hotel 1: Cox Hotel</p></section>',
        ];

        Http::fake([
            'https://llm.example/v1/chat/completions' => Http::sequence()
                ->push([
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
                ], 200)
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'content' => json_encode($llmHtml, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
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
            ->assertJsonPath('presentation_instruction', 'Present travel results as attractive, professional HTML. Start with a short natural-language summary like "There are 5 trips available for Cox\'s Bazar", then show a quick comparison table when useful, followed by numbered sections such as "Trip 1" or "Hotel 2". Keep the tone human and polished, highlight important details like price, status, rating, and description, and never dump raw JSON. When trips are shown, add a simple booking hint such as "Reply: book the Beach Flight trip" so the customer knows chat can show available seats next.')
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

        $this->assertStringContainsString('llm-full', $fullHtml);
        $this->assertStringContainsString("Cox's Bazar Travel Plan", $fullHtml);
        $this->assertStringContainsString('There are 3 travel resources ready for you.', $fullHtml);
        $this->assertStringContainsString('Cox Trip', $tripsHtml);
        $this->assertStringContainsString('Trip 1', $tripsHtml);
        $this->assertSame($llmHtml['summary'], $response->json('html.summary'));
        $this->assertSame($llmHtml['trips'], $response->json('html.trips'));
        $this->assertSame($llmHtml['packages'], $response->json('html.packages'));
        $this->assertSame($llmHtml['hotels'], $response->json('html.hotels'));

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://llm.example/v1/chat/completions'
            && $request['model'] === 'gpt-4o-mini'
            && data_get($request->data(), 'messages.1.content') === "Give all information about Cox's Bazar trip, package and hotel");

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://llm.example/v1/chat/completions'
            && str_contains((string) data_get($request->data(), 'messages.1.content'), '"customer_message": "Give all information about Cox\'s Bazar trip, package and hotel"')
            && str_contains((string) data_get($request->data(), 'messages.1.content'), '"location": "Cox\'s Bazar"'));

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

        $llmHtml = [
            'full' => '<section class="llm-full"><p>There is 1 trip available for Cox\'s Bazar.</p></section>',
            'summary' => '<section class="llm-summary"><p>We found one trip for Cox\'s Bazar.</p></section>',
            'trips' => '<section class="llm-trips"><h3>Trips</h3><p>Trip 1: Beach Flight</p></section>',
            'packages' => '',
            'hotels' => '',
        ];

        Http::fake([
            'https://llm.example/v1/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'content' => '{"location":"Cox\'s Bazar","resources":["trips"]}',
                            ],
                        ],
                    ],
                ], 200)
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'content' => json_encode($llmHtml, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
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
        $this->assertSame($llmHtml['trips'], $response->json('html.trips'));
        $this->assertSame($llmHtml['full'], $response->json('html.full'));

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://llm.example/v1/chat/completions'
            && data_get($request->data(), 'messages.1.content') === "fetch all information of cox's bazer trips");

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://llm.example/v1/chat/completions'
            && str_contains((string) data_get($request->data(), 'messages.1.content'), '"requested_resources": ['));

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://travelbooking.infinitycodehubltd.com/public/api/trips'
            && $request->data() === ['location' => "Cox's Bazar"]);

        Http::assertSentCount(3);
    }

    public function test_it_can_lookup_a_named_hotel_and_answer_a_location_question(): void
    {
        config()->set('services.travel_intent_llm', [
            'base_url' => 'https://llm.example/v1',
            'api_key' => 'test-key',
            'model' => 'gpt-4o-mini',
            'connect_timeout' => 5,
            'timeout' => 20,
            'temperature' => 0,
        ]);

        $llmHtml = [
            'full' => '<section class="llm-full"><p>Sea Place Hotel is located in Cox\'s Bazar, Bangladesh.</p></section>',
            'summary' => '<section class="llm-summary"><p>Sea Place Hotel is in Cox\'s Bazar, Bangladesh.</p></section>',
            'trips' => '',
            'packages' => '',
            'hotels' => '<section class="llm-hotels"><h3>Hotels</h3><p>Sea Place Hotel is listed at Kolatoli Road, Cox\'s Bazar, Bangladesh.</p></section>',
        ];

        Http::fake([
            'https://llm.example/v1/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'content' => json_encode([
                                    'search_term' => 'Sea Place Hotel',
                                    'location' => null,
                                    'resources' => ['hotels'],
                                    'question_focus' => 'location',
                                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                            ],
                        ],
                    ],
                ], 200)
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'content' => json_encode($llmHtml, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                            ],
                        ],
                    ],
                ], 200),
            'https://travelbooking.infinitycodehubltd.com/public/api/hotels*' => Http::response([
                'data' => [
                    [
                        'name' => 'Sea Place Hotel',
                        'location' => "Cox's Bazar",
                        'country' => 'Bangladesh',
                        'address' => 'Kolatoli Road',
                        'status' => 1,
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer test-token')
            ->postJson('/api/chat', [
                'message' => 'What is the location of Sea Place Hotel?',
            ]);

        $response->assertOk()
            ->assertJsonPath('parsed.search_term', 'Sea Place Hotel')
            ->assertJsonPath('parsed.location', null)
            ->assertJsonPath('parsed.question_focus', 'location')
            ->assertJsonPath('data.hotels.error', false);

        $this->assertSame(['hotels'], $response->json('parsed.resources'));
        $this->assertSame(['hotels'], array_keys($response->json('data')));
        $this->assertSame($llmHtml['summary'], $response->json('html.summary'));
        $this->assertStringContainsString("Cox's Bazar", (string) $response->json('html.full'));

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://llm.example/v1/chat/completions'
            && data_get($request->data(), 'messages.1.content') === 'What is the location of Sea Place Hotel?');

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://llm.example/v1/chat/completions'
            && str_contains((string) data_get($request->data(), 'messages.1.content'), '"search_term": "Sea Place Hotel"')
            && str_contains((string) data_get($request->data(), 'messages.1.content'), '"question_focus": "location"'));

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://travelbooking.infinitycodehubltd.com/public/api/hotels'
            && $request->data() === ['location' => 'Sea Place Hotel']);

        Http::assertSentCount(3);
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

    public function test_it_returns_only_full_html_by_default_when_configured(): void
    {
        config()->set('services.travel_chat.full_html_only', true);
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
                                'title' => 'Booking payment issue',
                                'description' => 'My booking payment failed and I need support to check it.',
                                'remarks' => null,
                                'needs_more_information' => false,
                            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ], 200),
            'https://travelbooking.infinitycodehubltd.com/public/api/user/createTicket' => Http::response([
                'isExecute' => true,
                'message' => 'Ticket created successfully.',
                'data' => [
                    'id' => 91,
                ],
            ], 201),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer compact-token')
            ->postJson('/api/chat', [
                'message' => 'Please create a ticket because my booking payment failed.',
            ]);

        $response->assertOk();

        $this->assertSame(['html'], array_keys($response->json()));
        $this->assertStringContainsString('Ticket Created', (string) $response->json('html.full'));
    }

    public function test_it_falls_back_to_renderer_when_response_generation_llm_fails(): void
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
            'https://llm.example/v1/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [
                        [
                            'message' => [
                                'content' => '{"location":"Cox\'s Bazar","resources":["trips"]}',
                            ],
                        ],
                    ],
                ], 200)
                ->push([
                    'error' => [
                        'message' => 'Temporarily overloaded.',
                    ],
                ], 429),
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
            ->assertJsonPath('parsed.location', "Cox's Bazar");

        $this->assertStringContainsString('Trip 1', (string) $response->json('html.trips'));
        $this->assertStringContainsString('Beach Flight', (string) $response->json('html.trips'));
        $this->assertStringContainsString('Reply: book the Beach Flight trip', (string) $response->json('html.trips'));
        $this->assertStringContainsString('travel-chat-response', (string) $response->json('html.full'));
    }

    public function test_it_can_show_available_trip_seats_when_customer_books_by_trip_name(): void
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
                                'action' => 'book_trip',
                                'trip_id' => null,
                                'trip_name' => 'Beach Flight',
                                'seat_numbers' => [],
                                'hotel_id' => null,
                                'hotel_room_id' => null,
                                'check_in_date' => null,
                                'check_out_date' => null,
                                'total_persons' => null,
                                'payment_method' => null,
                                'payment_reference' => null,
                                'needs_more_information' => true,
                            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ], 200),
            'https://travelbooking.infinitycodehubltd.com/public/api/trips' => Http::response([
                'data' => [
                    [
                        'id' => 15,
                        'trip_name' => 'Beach Flight',
                        'destination' => "Cox's Bazar",
                        'price' => '550.00',
                    ],
                ],
            ], 200),
            'https://travelbooking.infinitycodehubltd.com/public/api/user/tripsummery' => Http::response([
                'isExecture' => 'success',
                'data' => [
                    'tripSummaries' => [
                        [
                            'trip_id' => 15,
                            'trip_name' => 'Beach Flight',
                            'price' => '550.00',
                        ],
                    ],
                    'seat_layout' => [
                        ['trip_id' => 15, 'seat_id' => 101, 'seat_number' => 'A1', 'vehicle_name' => 'Nobo Air', 'is_available' => 1],
                        ['trip_id' => 15, 'seat_id' => 102, 'seat_number' => 'A2', 'vehicle_name' => 'Nobo Air', 'is_available' => 1],
                        ['trip_id' => 15, 'seat_id' => 104, 'seat_number' => 'A4', 'vehicle_name' => 'Nobo Air', 'is_available' => 1],
                        ['trip_id' => 15, 'seat_id' => 106, 'seat_number' => 'A6', 'vehicle_name' => 'Nobo Air', 'is_available' => 1],
                    ],
                ],
                'message' => 'success',
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer trip-name-token')
            ->postJson('/api/chat', [
                'message' => 'Book the Beach Flight trip',
            ]);

        $response->assertOk()
            ->assertJsonPath('action', 'book_trip')
            ->assertJsonPath('booking.type', 'trip')
            ->assertJsonPath('booking.created', false)
            ->assertJsonPath('booking.needs_more_information', true)
            ->assertJsonPath('booking.payload.trip_id', 15)
            ->assertJsonPath('booking.payload.trip_name', 'Beach Flight')
            ->assertJsonPath('booking.seat_information.0.seat_number', 'A1')
            ->assertJsonPath('booking.seat_information.0.is_available', true)
            ->assertJsonPath('message', 'These seats are currently available for this trip. Reply with the seat numbers you want to reserve.');

        $this->assertSame(['A1', 'A2', 'A4', 'A6'], $response->json('booking.available_seat_numbers'));
        $this->assertStringContainsString('A1, A2, A4, A6', (string) $response->json('html.full'));
        $this->assertStringContainsString('book the Beach Flight trip, seats are A1,A2', (string) $response->json('html.full'));

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://travelbooking.infinitycodehubltd.com/public/api/trips'
            && $request->data() === ['location' => 'Beach Flight']);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://travelbooking.infinitycodehubltd.com/public/api/user/tripsummery'
            && $request->data() === ['trip_id' => 15]);
    }

    public function test_it_requests_payment_method_after_trip_name_and_seats_are_selected(): void
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
                                'action' => 'book_trip',
                                'trip_id' => null,
                                'trip_name' => 'Beach Flight',
                                'seat_numbers' => ['A1', 'A2', 'A4', 'A6'],
                                'hotel_id' => null,
                                'hotel_room_id' => null,
                                'check_in_date' => null,
                                'check_out_date' => null,
                                'total_persons' => null,
                                'payment_method' => null,
                                'payment_reference' => null,
                                'needs_more_information' => true,
                            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ], 200),
            'https://travelbooking.infinitycodehubltd.com/public/api/trips' => Http::response([
                'data' => [
                    [
                        'id' => 15,
                        'trip_name' => 'Beach Flight',
                        'destination' => "Cox's Bazar",
                        'price' => '550.00',
                    ],
                ],
            ], 200),
            'https://travelbooking.infinitycodehubltd.com/public/api/user/tripsummery' => Http::response([
                'isExecture' => 'success',
                'data' => [
                    'tripSummaries' => [
                        [
                            'trip_id' => 15,
                            'trip_name' => 'Beach Flight',
                            'price' => '550.00',
                        ],
                    ],
                    'seat_layout' => [
                        ['trip_id' => 15, 'seat_id' => 101, 'seat_number' => 'A1', 'vehicle_name' => 'Nobo Air', 'is_available' => 1],
                        ['trip_id' => 15, 'seat_id' => 102, 'seat_number' => 'A2', 'vehicle_name' => 'Nobo Air', 'is_available' => 1],
                        ['trip_id' => 15, 'seat_id' => 104, 'seat_number' => 'A4', 'vehicle_name' => 'Nobo Air', 'is_available' => 1],
                        ['trip_id' => 15, 'seat_id' => 106, 'seat_number' => 'A6', 'vehicle_name' => 'Nobo Air', 'is_available' => 1],
                    ],
                ],
                'message' => 'success',
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer trip-seat-token')
            ->postJson('/api/chat', [
                'message' => 'Book the Beach Flight trip, seats are A1,A2,A4,A6',
            ]);

        $response->assertOk()
            ->assertJsonPath('action', 'book_trip')
            ->assertJsonPath('booking.type', 'trip')
            ->assertJsonPath('booking.created', false)
            ->assertJsonPath('booking.needs_more_information', true)
            ->assertJsonPath('booking.payload.trip_id', 15)
            ->assertJsonPath('booking.payload.trip_name', 'Beach Flight')
            ->assertJsonPath('booking.amount', 2200)
            ->assertJsonPath('message', 'Please send the payment method to finish the trip booking. Use card, bkash, nagad, or internet banking.');

        $this->assertSame(['A1', 'A2', 'A4', 'A6'], $response->json('booking.selected_seat_numbers'));
        $this->assertStringContainsString('Choose Payment Method', (string) $response->json('html.full'));
        $this->assertStringContainsString('book the Beach Flight trip, seats are A1,A2,A4,A6, payment by bkash 01700000000', (string) $response->json('html.full'));

        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://travelbooking.infinitycodehubltd.com/public/api/booking');
    }

    public function test_it_ignores_trip_reference_numbers_inside_the_trip_title_and_books_the_resolved_trip(): void
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
                                'action' => 'book_trip',
                                'trip_id' => 2849149,
                                'trip_name' => "Grand trip Cox's Bazer",
                                'seat_numbers' => ['A1', 'A2'],
                                'hotel_id' => null,
                                'hotel_room_id' => null,
                                'check_in_date' => null,
                                'check_out_date' => null,
                                'total_persons' => null,
                                'payment_method' => 'bkash',
                                'payment_reference' => '01628781323',
                                'needs_more_information' => false,
                            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ], 200),
            'https://travelbooking.infinitycodehubltd.com/public/api/trips' => Http::response([
                'data' => [
                    [
                        'id' => 28,
                        'trip_name' => "Grand trip Cox's Bazer",
                        'destination' => "Cox's Bazer",
                        'price' => '550.00',
                    ],
                ],
            ], 200),
            'https://travelbooking.infinitycodehubltd.com/public/api/user/tripsummery' => Http::response([
                'isExecture' => 'success',
                'data' => [
                    'tripSummaries' => [
                        [
                            'trip_id' => 28,
                            'trip_name' => "Grand trip Cox's Bazer",
                            'price' => '550.00',
                        ],
                    ],
                    'seat_layout' => [
                        ['trip_id' => 28, 'seat_id' => 201, 'seat_number' => 'A1', 'vehicle_name' => 'Nobo Air', 'is_available' => 1],
                        ['trip_id' => 28, 'seat_id' => 202, 'seat_number' => 'A2', 'vehicle_name' => 'Nobo Air', 'is_available' => 1],
                    ],
                ],
                'message' => 'success',
            ], 200),
            'https://travelbooking.infinitycodehubltd.com/public/api/booking' => Http::response([
                'isExecture' => 'success',
                'data' => [
                    'booking_id' => 108,
                    'trip_id' => 28,
                    'payment_id' => 501,
                ],
                'message' => 'Booking successfully created!',
            ], 201),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer named-trip-token')
            ->postJson('/api/chat', [
                'message' => "book the Grand trip Cox's Bazer #2849149 trip, seats are A1,A2, payment by bkash 01628781323",
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('booking.payload.trip_id', 28)
            ->assertJsonPath('booking.payload.trip_name', "Grand trip Cox's Bazer")
            ->assertJsonPath('message', 'Booking successfully created!');

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://travelbooking.infinitycodehubltd.com/public/api/user/tripsummery'
            && $request->data() === ['trip_id' => 28]);

        Http::assertNotSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://travelbooking.infinitycodehubltd.com/public/api/user/tripsummery'
            && $request->data() === ['trip_id' => 2849149]);
    }

    public function test_it_can_create_an_authenticated_trip_booking_from_chat(): void
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
                                'action' => 'book_trip',
                                'trip_id' => 15,
                                'seat_numbers' => ['A1', 'A2'],
                                'hotel_id' => null,
                                'hotel_room_id' => null,
                                'check_in_date' => null,
                                'check_out_date' => null,
                                'total_persons' => null,
                                'payment_method' => 'bkash',
                                'payment_reference' => '01700000000',
                                'needs_more_information' => false,
                            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ], 200),
            'https://travelbooking.infinitycodehubltd.com/public/api/user/tripsummery' => Http::response([
                'isExecture' => 'success',
                'data' => [
                    'tripSummaries' => [
                        [
                            'trip_id' => 15,
                            'trip_name' => 'Beach Flight',
                            'price' => '550.00',
                        ],
                    ],
                    'seat_layout' => [
                        ['trip_id' => 15, 'seat_id' => 101, 'seat_number' => 'A1', 'vehicle_name' => 'Nobo Air', 'is_available' => 1],
                        ['trip_id' => 15, 'seat_id' => 102, 'seat_number' => 'A2', 'vehicle_name' => 'Nobo Air', 'is_available' => 1],
                        ['trip_id' => 15, 'seat_id' => 103, 'seat_number' => 'A3', 'vehicle_name' => 'Nobo Air', 'is_available' => 0],
                    ],
                ],
                'message' => 'success',
            ], 200),
            'https://travelbooking.infinitycodehubltd.com/public/api/booking' => Http::response([
                'isExecture' => 'success',
                'data' => [
                    'booking_id' => 99,
                    'trip_id' => 15,
                    'payment_id' => 88,
                    'transaction_reference' => 'TRIPREF001',
                ],
                'message' => 'Booking successfully created!',
            ], 201),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer test-token')
            ->postJson('/api/chat', [
                'message' => 'Book trip id 15 seat A1 and A2 with bkash 01700000000',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('action', 'book_trip')
            ->assertJsonPath('authentication.synced', true)
            ->assertJsonPath('booking.type', 'trip')
            ->assertJsonPath('booking.created', true)
            ->assertJsonPath('booking.payload.trip_id', 15)
            ->assertJsonPath('booking.payload.trip_name', 'Beach Flight')
            ->assertJsonPath('booking.payload.payment_method', 'bkash')
            ->assertJsonPath('booking.payload.amount', 1100)
            ->assertJsonPath('message', 'Booking successfully created!');

        $this->assertSame(['A1', 'A2'], $response->json('booking.payload.seat_numbers'));
        $this->assertStringContainsString('Trip Booking Created', (string) $response->json('html.full'));

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://travelbooking.infinitycodehubltd.com/public/api/user/tripsummery'
            && $request->data() === ['trip_id' => 15]
            && $request->hasHeader('Authorization', ['Bearer test-token']));

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://travelbooking.infinitycodehubltd.com/public/api/booking'
            && $request->data() === [
                'seatinfo' => [
                    ['trip_id' => 15, 'seat_id' => 101, 'seat_number' => 'A1'],
                    ['trip_id' => 15, 'seat_id' => 102, 'seat_number' => 'A2'],
                ],
                'paymentinfo' => [
                    'amount' => 1100.0,
                    'payment_method' => 'bkash',
                    'bkash' => '01700000000',
                ],
            ]);
    }

    public function test_it_returns_a_payment_link_in_chat_when_trip_booking_requires_redirect_payment(): void
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
                                'action' => 'book_trip',
                                'trip_id' => 15,
                                'seat_numbers' => ['A1', 'A2'],
                                'hotel_id' => null,
                                'hotel_room_id' => null,
                                'check_in_date' => null,
                                'check_out_date' => null,
                                'total_persons' => null,
                                'payment_method' => 'bkash',
                                'payment_reference' => '01700000000',
                                'needs_more_information' => false,
                            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ], 200),
            'https://travelbooking.infinitycodehubltd.com/public/api/user/tripsummery' => Http::response([
                'isExecture' => 'success',
                'data' => [
                    'tripSummaries' => [
                        [
                            'trip_id' => 15,
                            'trip_name' => 'Beach Flight',
                            'price' => '550.00',
                        ],
                    ],
                    'seat_layout' => [
                        ['trip_id' => 15, 'seat_id' => 101, 'seat_number' => 'A1', 'vehicle_name' => 'Nobo Air', 'is_available' => 1],
                        ['trip_id' => 15, 'seat_id' => 102, 'seat_number' => 'A2', 'vehicle_name' => 'Nobo Air', 'is_available' => 1],
                    ],
                ],
                'message' => 'success',
            ], 200),
            'https://travelbooking.infinitycodehubltd.com/public/api/booking' => Http::response([
                'isExecture' => 'success',
                'data' => [
                    'booking_id' => 99,
                    'trip_id' => 15,
                    'redirected_url' => 'https://pay.example/trip/99',
                ],
                'message' => 'Booking successfully created!',
            ], 201),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer test-token')
            ->postJson('/api/chat', [
                'message' => 'Book trip id 15 seat A1 and A2 with bkash 01700000000',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('booking.payload.redirected_url', 'https://pay.example/trip/99')
            ->assertJsonPath('message', 'Booking successfully created. Complete the bKash payment using the payment link below.');

        $this->assertStringContainsString('Open Payment Link', (string) $response->json('html.full'));
        $this->assertStringContainsString('https://pay.example/trip/99', (string) $response->json('html.full'));
        $this->assertStringContainsString('Complete the bKash payment using the secure link below.', (string) $response->json('html.full'));
    }

    public function test_it_shows_available_seats_when_customer_only_mentions_the_trip_id_for_booking(): void
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
                                'action' => 'book_trip',
                                'trip_id' => 15,
                                'seat_numbers' => [],
                                'hotel_id' => null,
                                'hotel_room_id' => null,
                                'check_in_date' => null,
                                'check_out_date' => null,
                                'total_persons' => null,
                                'payment_method' => null,
                                'payment_reference' => null,
                                'needs_more_information' => true,
                            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ], 200),
            'https://travelbooking.infinitycodehubltd.com/public/api/user/tripsummery' => Http::response([
                'isExecture' => 'success',
                'data' => [
                    'tripSummaries' => [
                        [
                            'trip_id' => 15,
                            'trip_name' => 'Beach Flight',
                            'price' => '550.00',
                        ],
                    ],
                    'seat_layout' => [
                        ['trip_id' => 15, 'seat_id' => 101, 'seat_number' => 'A1', 'vehicle_name' => 'Nobo Air', 'is_available' => 1],
                        ['trip_id' => 15, 'seat_id' => 102, 'seat_number' => 'A2', 'vehicle_name' => 'Nobo Air', 'is_available' => 1],
                    ],
                ],
                'message' => 'success',
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer test-token')
            ->postJson('/api/chat', [
                'message' => 'I want to book trip id 15',
            ]);

        $response->assertOk()
            ->assertJsonPath('action', 'book_trip')
            ->assertJsonPath('booking.type', 'trip')
            ->assertJsonPath('booking.created', false)
            ->assertJsonPath('booking.needs_more_information', true)
            ->assertJsonPath('booking.payload.trip_id', 15)
            ->assertJsonPath('booking.seat_information.0.seat_number', 'A1')
            ->assertJsonPath('message', 'These seats are currently available for this trip. Reply with the seat numbers you want to reserve.');

        $this->assertSame(['A1', 'A2'], $response->json('booking.available_seat_numbers'));
        $this->assertStringContainsString('Choose Trip Seats', (string) $response->json('html.full'));

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://travelbooking.infinitycodehubltd.com/public/api/user/tripsummery'
            && $request->data() === ['trip_id' => 15]);
    }

    public function test_it_can_create_an_authenticated_hotel_booking_from_chat(): void
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
                                'action' => 'book_hotel',
                                'trip_id' => null,
                                'seat_numbers' => [],
                                'hotel_id' => 9,
                                'hotel_room_id' => 21,
                                'check_in_date' => '2026-06-10',
                                'check_out_date' => '2026-06-12',
                                'total_persons' => 2,
                                'payment_method' => 'card',
                                'payment_reference' => '42424242',
                                'needs_more_information' => false,
                            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ], 200),
            'https://travelbooking.infinitycodehubltd.com/public/api/hotel/9' => Http::response([
                'isExecture' => 'success',
                'data' => [
                    'hotel' => [
                        'id' => 9,
                        'name' => 'Sea Place Hotel',
                        'rooms' => [
                            [
                                'room_id' => 21,
                                'max_occupancy' => 3,
                                'prices' => [
                                    [
                                        'season_start' => '2026-06-01',
                                        'season_end' => '2026-06-30',
                                        'price_per_night' => '3500.00',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'message' => 'success',
            ], 200),
            'https://travelbooking.infinitycodehubltd.com/public/api/hotel/booking' => Http::response([
                'isExecute' => true,
                'data' => 501,
                'message' => 'Hotel booking successfully',
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer hotel-token')
            ->postJson('/api/chat', [
                'message' => 'Book hotel id 9 room id 21 from 2026-06-10 to 2026-06-12 for 2 guests with card 42424242',
            ]);

        $response->assertOk()
            ->assertJsonPath('action', 'book_hotel')
            ->assertJsonPath('booking.type', 'hotel')
            ->assertJsonPath('booking.created', true)
            ->assertJsonPath('booking.payload.hotel_id', 9)
            ->assertJsonPath('booking.payload.hotel_name', 'Sea Place Hotel')
            ->assertJsonPath('booking.payload.hotel_room_id', 21)
            ->assertJsonPath('booking.payload.total_persons', 2)
            ->assertJsonPath('booking.payload.total_cost', 7000)
            ->assertJsonPath('message', 'Hotel booking successfully');

        $this->assertStringContainsString('Hotel Booking Created', (string) $response->json('html.full'));

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://travelbooking.infinitycodehubltd.com/public/api/hotel/9'
            && $request->hasHeader('Authorization', ['Bearer hotel-token']));

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://travelbooking.infinitycodehubltd.com/public/api/hotel/booking'
            && $request->data() === [
                'hotel_id' => 9,
                'hotel_room_id' => 21,
                'check_in_date' => '2026-06-10',
                'check_out_date' => '2026-06-12',
                'total_persons' => 2,
                'total_cost' => 7000.0,
                'payment_method' => 'card',
                'card' => '42424242',
            ]);
    }

    public function test_it_returns_a_payment_link_in_chat_when_hotel_booking_requires_redirect_payment(): void
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
                                'action' => 'book_hotel',
                                'trip_id' => null,
                                'seat_numbers' => [],
                                'hotel_id' => 9,
                                'hotel_room_id' => 21,
                                'check_in_date' => '2026-06-10',
                                'check_out_date' => '2026-06-12',
                                'total_persons' => 2,
                                'payment_method' => 'card',
                                'payment_reference' => '42424242',
                                'needs_more_information' => false,
                            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ], 200),
            'https://travelbooking.infinitycodehubltd.com/public/api/hotel/9' => Http::response([
                'isExecture' => 'success',
                'data' => [
                    'hotel' => [
                        'id' => 9,
                        'name' => 'Sea Place Hotel',
                        'rooms' => [
                            [
                                'room_id' => 21,
                                'max_occupancy' => 3,
                                'prices' => [
                                    [
                                        'season_start' => '2026-06-01',
                                        'season_end' => '2026-06-30',
                                        'price_per_night' => '3500.00',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'message' => 'success',
            ], 200),
            'https://travelbooking.infinitycodehubltd.com/public/api/hotel/booking' => Http::response([
                'isExecute' => true,
                'data' => [
                    'booking_id' => 501,
                    'redirect_url' => 'https://pay.example/hotel/501',
                ],
                'message' => 'Hotel booking successfully',
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer hotel-token')
            ->postJson('/api/chat', [
                'message' => 'Book hotel id 9 room id 21 from 2026-06-10 to 2026-06-12 for 2 guests with card 42424242',
            ]);

        $response->assertOk()
            ->assertJsonPath('booking.payload.redirected_url', 'https://pay.example/hotel/501')
            ->assertJsonPath('message', 'Hotel booking successfully. Complete the card payment using the payment link below.');

        $this->assertStringContainsString('Open Payment Link', (string) $response->json('html.full'));
        $this->assertStringContainsString('https://pay.example/hotel/501', (string) $response->json('html.full'));
        $this->assertStringContainsString('Complete the card payment using the secure link below.', (string) $response->json('html.full'));
    }

    public function test_it_can_create_an_authenticated_customer_ticket_from_chat(): void
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
                                'title' => 'Refund pending for booking BK-104',
                                'description' => 'My refund for booking BK-104 has not arrived yet. Please check it.',
                                'remarks' => null,
                                'needs_more_information' => false,
                            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ], 200),
            'https://travelbooking.infinitycodehubltd.com/public/api/user/createTicket' => Http::response([
                'isExecute' => true,
                'data' => [],
                'message' => 'Ticket created successfully.',
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer test-token')
            ->postJson('/api/chat', [
                'message' => 'Please create a ticket because my refund for booking BK-104 has not arrived yet.',
            ]);

        $response->assertOk()
            ->assertJsonPath('action', 'create_ticket')
            ->assertJsonPath('authentication.synced', true)
            ->assertJsonPath('authentication.method', 'forwarded_bearer_token')
            ->assertJsonPath('ticket.created', true)
            ->assertJsonPath('ticket.payload.title', 'Refund pending for booking BK-104')
            ->assertJsonPath('ticket.payload.description', 'My refund for booking BK-104 has not arrived yet. Please check it.')
            ->assertJsonPath('ticket.endpoint', 'https://travelbooking.infinitycodehubltd.com/public/api/user/createTicket')
            ->assertJsonPath('message', 'Ticket created successfully.');

        $this->assertStringContainsString('Ticket Created', (string) $response->json('html.full'));
        $this->assertStringContainsString('Refund pending for booking BK-104', (string) $response->json('html.full'));

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://llm.example/v1/chat/completions'
            && data_get($request->data(), 'messages.1.content') === 'Please create a ticket because my refund for booking BK-104 has not arrived yet.');

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://travelbooking.infinitycodehubltd.com/public/api/user/createTicket'
            && $request->data() === [
                'title' => 'Refund pending for booking BK-104',
                'description' => 'My refund for booking BK-104 has not arrived yet. Please check it.',
            ]
            && $request->hasHeader('Authorization', ['Bearer test-token']));

        Http::assertSentCount(2);
    }

    public function test_it_requests_more_ticket_information_when_the_chat_message_is_too_vague(): void
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
                            'content' => '{"title":null,"description":null,"remarks":null,"needs_more_information":true}',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer test-token')
            ->postJson('/api/chat', [
                'message' => 'Please create a support ticket.',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('action', 'create_ticket')
            ->assertJsonPath('ticket.created', false)
            ->assertJsonPath('ticket.needs_more_information', true)
            ->assertJsonPath('message', 'Please share the support issue in more detail so the ticket can be created.');

        $this->assertStringContainsString('More Detail Needed', (string) $response->json('html.full'));

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://llm.example/v1/chat/completions');

        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://travelbooking.infinitycodehubltd.com/public/api/user/createTicket');
        Http::assertSentCount(1);
    }

    public function test_it_returns_the_upstream_authentication_error_when_ticket_creation_is_rejected(): void
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
                                'title' => 'Booking payment issue',
                                'description' => 'My booking payment failed and I need support to check it.',
                                'remarks' => null,
                                'needs_more_information' => false,
                            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ], 200),
            'https://travelbooking.infinitycodehubltd.com/public/api/user/createTicket' => Http::response([
                'message' => 'Unauthenticated.',
            ], 401),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer expired-token')
            ->postJson('/api/chat', [
                'message' => 'Please create a ticket because my booking payment failed.',
            ]);

        $response->assertStatus(401)
            ->assertJsonPath('action', 'create_ticket')
            ->assertJsonPath('ticket.created', false)
            ->assertJsonPath('authentication.synced', true)
            ->assertJsonPath('message', 'The remote TravelBooking ticket API rejected the forwarded bearer token.');

        $this->assertStringContainsString('Ticket Was Not Created', (string) $response->json('html.full'));

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://travelbooking.infinitycodehubltd.com/public/api/user/createTicket'
            && $request->hasHeader('Authorization', ['Bearer expired-token']));
    }
}
