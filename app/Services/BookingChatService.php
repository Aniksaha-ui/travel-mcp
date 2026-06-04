<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BookingChatService
{
    public function __construct(
        private readonly BookingChatParser $bookingChatParser,
        private readonly TravelBookingApiClient $travelBookingApiClient,
        private readonly BookingChatHtmlRenderer $bookingChatHtmlRenderer,
    ) {
    }

    public function shouldHandle(string $message): bool
    {
        return $this->bookingChatParser->shouldHandle($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(string $message, string $bearerToken): array
    {
        $parsed = $this->bookingChatParser->parse($message);

        return match ($parsed['action']) {
            'book_trip' => $this->handleTripBooking($parsed, $message, $bearerToken),
            'book_hotel' => $parsed['needs_more_information']
                ? $this->needsMoreInformationResponse($parsed, $message)
                : $this->handleHotelBooking($parsed, $message, $bearerToken),
            default => $this->needsMoreInformationResponse($parsed, $message),
        };
    }

    /**
     * @param  array{
     *     action: 'book_trip'|'book_hotel'|null,
     *     trip_id: int|null,
     *     trip_name: string|null,
     *     seat_numbers: array<int, string>,
     *     hotel_id: int|null,
     *     hotel_room_id: int|null,
     *     check_in_date: string|null,
     *     check_out_date: string|null,
     *     total_persons: int|null,
     *     payment_method: string|null,
     *     payment_reference: string|null,
     *     needs_more_information: bool
     * }  $parsed
     * @return array<string, mixed>
     */
    private function handleTripBooking(array $parsed, string $message, string $bearerToken): array
    {
        $resolvedTrip = $this->resolveTripForBooking($bearerToken, $parsed);

        if (($resolvedTrip['error'] ?? false) === true) {
            return $this->failureResponse(
                type: 'trip',
                message: (string) ($resolvedTrip['message'] ?? 'The trip could not be resolved for booking.'),
                status: (int) ($resolvedTrip['status'] ?? 422),
                parsed: $parsed,
                extra: [
                    'Trip' => $parsed['trip_name'],
                    'Trip ID' => $parsed['trip_id'],
                ],
                endpoint: $resolvedTrip['endpoint'] ?? null,
                upstream: $resolvedTrip['upstream'] ?? null,
                inputMessage: $message,
            );
        }

        $parsed['trip_id'] = $resolvedTrip['trip_id'];
        $parsed['trip_name'] = $resolvedTrip['trip_name'];

        $summary = $this->travelBookingApiClient->fetchTripSummary($bearerToken, (int) $parsed['trip_id']);

        if ($this->tripSummaryIsEmpty($summary) && is_string($parsed['trip_name']) && trim($parsed['trip_name']) !== '') {
            $recoveredTrip = $this->resolveTripFromName($bearerToken, (string) $parsed['trip_name']);

            if (($recoveredTrip['error'] ?? true) === false && (int) $recoveredTrip['trip_id'] !== (int) $parsed['trip_id']) {
                $parsed['trip_id'] = (int) $recoveredTrip['trip_id'];
                $parsed['trip_name'] = (string) $recoveredTrip['trip_name'];
                $summary = $this->travelBookingApiClient->fetchTripSummary($bearerToken, (int) $parsed['trip_id']);
            }
        }

        if (($summary['error'] ?? true) === true) {
            return $this->failureResponse(
                type: 'trip',
                message: (string) ($summary['message'] ?? 'Trip booking data could not be loaded.'),
                status: (int) ($summary['status'] ?? 502),
                parsed: $parsed,
                extra: [
                    'Trip' => $parsed['trip_name'],
                    'Trip ID' => $parsed['trip_id'],
                    'Requested Seats' => implode(', ', $parsed['seat_numbers']),
                ],
                endpoint: $summary['endpoint'] ?? null,
                upstream: $summary['details']['response'] ?? null,
                inputMessage: $message,
            );
        }

        $trip = Arr::first(
            data_get($summary, 'data.data.tripSummaries', []),
            static fn (mixed $candidate): bool => is_array($candidate),
        );
        $seatLayout = array_values(array_filter(
            data_get($summary, 'data.data.seat_layout', []),
            'is_array',
        ));

        if (! is_array($trip) || $seatLayout === []) {
            return $this->failureResponse(
                type: 'trip',
                message: 'Trip summary is available, but seat layout data could not be prepared for booking.',
                status: 422,
                parsed: $parsed,
                extra: [
                    'Trip' => $parsed['trip_name'],
                    'Trip ID' => $parsed['trip_id'],
                    'Requested Seats' => implode(', ', $parsed['seat_numbers']),
                ],
                endpoint: $summary['endpoint'] ?? null,
                upstream: $summary['data'] ?? null,
                inputMessage: $message,
            );
        }

        $availableSeatNumbers = $this->availableSeatNumbers($seatLayout);

        if ($parsed['seat_numbers'] === []) {
            return $this->seatSelectionResponse(
                parsed: $parsed,
                trip: $trip,
                seatLayout: $seatLayout,
                availableSeatNumbers: $availableSeatNumbers,
                endpoint: $summary['endpoint'] ?? null,
                inputMessage: $message,
            );
        }

        $selectedSeats = $this->selectTripSeats($seatLayout, $parsed['seat_numbers']);
        $requestedSeats = $parsed['seat_numbers'];
        $foundSeatNumbers = array_map(
            static fn (array $seat): string => Str::upper((string) ($seat['seat_number'] ?? '')),
            $selectedSeats,
        );
        $missingSeats = array_values(array_diff($requestedSeats, $foundSeatNumbers));

        if ($missingSeats !== []) {
            return $this->failureResponse(
                type: 'trip',
                message: 'Some requested seats are unavailable or were not found for this trip.',
                status: 422,
                parsed: $parsed,
                extra: [
                    'Trip' => $parsed['trip_name'],
                    'Trip ID' => $parsed['trip_id'],
                    'Missing Seats' => implode(', ', $missingSeats),
                    'Requested Seats' => implode(', ', $requestedSeats),
                    'Available Seats' => $this->seatPreview($availableSeatNumbers),
                ],
                endpoint: $summary['endpoint'] ?? null,
                upstream: $summary['data'] ?? null,
                inputMessage: $message,
            );
        }

        $tripPrice = (float) data_get($trip, 'price', 0);

        if ($tripPrice <= 0) {
            return $this->failureResponse(
                type: 'trip',
                message: 'Trip pricing is unavailable, so the chat booking could not calculate the payment amount.',
                status: 422,
                parsed: $parsed,
                extra: [
                    'Trip' => $parsed['trip_name'],
                    'Trip ID' => $parsed['trip_id'],
                    'Requested Seats' => implode(', ', $requestedSeats),
                ],
                endpoint: $summary['endpoint'] ?? null,
                upstream: $summary['data'] ?? null,
                inputMessage: $message,
            );
        }

        $amount = round($tripPrice * count($selectedSeats), 2);

        if ($parsed['payment_method'] === null) {
            return $this->paymentMethodResponse(
                parsed: $parsed,
                trip: $trip,
                selectedSeats: $requestedSeats,
                amount: $amount,
                inputMessage: $message,
            );
        }

        if (
            in_array($parsed['payment_method'], ['card', 'bkash', 'nagad'], true)
            && $parsed['payment_reference'] === null
        ) {
            return $this->paymentReferenceResponse(
                parsed: $parsed,
                trip: $trip,
                selectedSeats: $requestedSeats,
                amount: $amount,
                inputMessage: $message,
            );
        }

        $payload = [
            'seatinfo' => array_map(fn (array $seat): array => [
                'trip_id' => (int) $parsed['trip_id'],
                'seat_id' => (int) $seat['seat_id'],
                'seat_number' => (string) $seat['seat_number'],
            ], $selectedSeats),
            'paymentinfo' => $this->tripPaymentPayload(
                paymentMethod: (string) $parsed['payment_method'],
                paymentReference: $parsed['payment_reference'],
                amount: $amount,
            ),
        ];

        $result = $this->travelBookingApiClient->createTripBooking($bearerToken, $payload);
        Log::info("booking response".json_encode($result));
        if (($result['error'] ?? true) === true) {
            return $this->failureResponse(
                type: 'trip',
                message: (string) ($result['message'] ?? 'Trip booking could not be created.'),
                status: (int) ($result['status'] ?? 502),
                parsed: $parsed,
                extra: [
                    'Trip' => $parsed['trip_name'],
                    'Trip ID' => $parsed['trip_id'],
                    'Seats' => implode(', ', $requestedSeats),
                    'Amount' => $this->formatMoney($amount),
                ],
                endpoint: $result['endpoint'] ?? null,
                upstream: $result['details']['response'] ?? null,
                inputMessage: $message,
            );
        }

        $redirectUrl = $this->extractRedirectUrlFromResult($result);

        return $this->successResponse(
            type: 'trip',
            message: (string) (data_get($result, 'data.message') ?? 'Trip booking created successfully.'),
            status: (int) ($result['status'] ?? 201),
            parsed: $parsed,
            payload: [
                'trip_id' => $parsed['trip_id'],
                'trip_name' => $parsed['trip_name'] ?? data_get($trip, 'trip_name'),
                'seat_numbers' => $requestedSeats,
                'payment_method' => $parsed['payment_method'],
                'amount' => $amount,
                'redirected_url' => $redirectUrl,
            ],
            endpoint: $result['endpoint'] ?? null,
            upstream: $result['data'] ?? null,
            inputMessage: $message,
        );
    }

    /**
     * @param  array{
     *     action: 'book_trip'|'book_hotel'|null,
     *     trip_id: int|null,
     *     trip_name: string|null,
     *     seat_numbers: array<int, string>,
     *     hotel_id: int|null,
     *     hotel_room_id: int|null,
     *     check_in_date: string|null,
     *     check_out_date: string|null,
     *     total_persons: int|null,
     *     payment_method: string|null,
     *     payment_reference: string|null,
     *     needs_more_information: bool
     * }  $parsed
     * @return array<string, mixed>
     */
    private function handleHotelBooking(array $parsed, string $message, string $bearerToken): array
    {
        $checkIn = CarbonImmutable::parse((string) $parsed['check_in_date']);
        $checkOut = CarbonImmutable::parse((string) $parsed['check_out_date']);

        if ($checkOut->lessThanOrEqualTo($checkIn)) {
            return $this->failureResponse(
                type: 'hotel',
                message: 'Check-out date must be later than the check-in date.',
                status: 422,
                parsed: $parsed,
                extra: [
                    'hotel_id' => $parsed['hotel_id'],
                    'room_id' => $parsed['hotel_room_id'],
                    'check_in_date' => $parsed['check_in_date'],
                    'check_out_date' => $parsed['check_out_date'],
                ],
                endpoint: null,
                upstream: null,
                inputMessage: $message,
            );
        }

        $hotelDetail = $this->travelBookingApiClient->fetchHotelDetail($bearerToken, (int) $parsed['hotel_id']);

        if (($hotelDetail['error'] ?? true) === true) {
            return $this->failureResponse(
                type: 'hotel',
                message: (string) ($hotelDetail['message'] ?? 'Hotel booking data could not be loaded.'),
                status: (int) ($hotelDetail['status'] ?? 502),
                parsed: $parsed,
                extra: [
                    'hotel_id' => $parsed['hotel_id'],
                    'room_id' => $parsed['hotel_room_id'],
                ],
                endpoint: $hotelDetail['endpoint'] ?? null,
                upstream: $hotelDetail['details']['response'] ?? null,
                inputMessage: $message,
            );
        }

        $hotel = data_get($hotelDetail, 'data.data.hotel');
        $rooms = array_values(array_filter(data_get($hotelDetail, 'data.data.hotel.rooms', []), 'is_array'));
        $room = Arr::first($rooms, fn (array $candidate): bool => (int) ($candidate['room_id'] ?? 0) === (int) $parsed['hotel_room_id']);

        if (! is_array($hotel) || ! is_array($room)) {
            return $this->failureResponse(
                type: 'hotel',
                message: 'The selected hotel room could not be found for booking.',
                status: 422,
                parsed: $parsed,
                extra: [
                    'hotel_id' => $parsed['hotel_id'],
                    'room_id' => $parsed['hotel_room_id'],
                ],
                endpoint: $hotelDetail['endpoint'] ?? null,
                upstream: $hotelDetail['data'] ?? null,
                inputMessage: $message,
            );
        }

        $maxOccupancy = (int) ($room['max_occupancy'] ?? 0);

        if ($maxOccupancy > 0 && (int) $parsed['total_persons'] > $maxOccupancy) {
            return $this->failureResponse(
                type: 'hotel',
                message: 'The selected room cannot accommodate that many guests.',
                status: 422,
                parsed: $parsed,
                extra: [
                    'hotel_id' => $parsed['hotel_id'],
                    'room_id' => $parsed['hotel_room_id'],
                    'requested_guests' => $parsed['total_persons'],
                    'max_occupancy' => $maxOccupancy,
                ],
                endpoint: $hotelDetail['endpoint'] ?? null,
                upstream: $hotelDetail['data'] ?? null,
                inputMessage: $message,
            );
        }

        $totalCost = $this->calculateHotelTotalCost($room, $checkIn, $checkOut);

        if ($totalCost === null) {
            return $this->failureResponse(
                type: 'hotel',
                message: 'Room pricing for the requested stay could not be calculated from the available seasonal rates.',
                status: 422,
                parsed: $parsed,
                extra: [
                    'hotel_id' => $parsed['hotel_id'],
                    'room_id' => $parsed['hotel_room_id'],
                    'check_in_date' => $parsed['check_in_date'],
                    'check_out_date' => $parsed['check_out_date'],
                ],
                endpoint: $hotelDetail['endpoint'] ?? null,
                upstream: $hotelDetail['data'] ?? null,
                inputMessage: $message,
            );
        }

        $payload = $this->hotelPayload($parsed, $totalCost);
        $result = $this->travelBookingApiClient->createHotelBooking($bearerToken, $payload);

        if (($result['error'] ?? true) === true) {
            return $this->failureResponse(
                type: 'hotel',
                message: (string) ($result['message'] ?? 'Hotel booking could not be created.'),
                status: (int) ($result['status'] ?? 502),
                parsed: $parsed,
                extra: [
                    'hotel_id' => $parsed['hotel_id'],
                    'hotel_name' => data_get($hotel, 'name'),
                    'room_id' => $parsed['hotel_room_id'],
                    'check_in_date' => $parsed['check_in_date'],
                    'check_out_date' => $parsed['check_out_date'],
                    'total_cost' => $this->formatMoney($totalCost),
                ],
                endpoint: $result['endpoint'] ?? null,
                upstream: $result['details']['response'] ?? null,
                inputMessage: $message,
            );
        }

        $redirectUrl = $this->extractRedirectUrlFromResult($result);

        return $this->successResponse(
            type: 'hotel',
            message: (string) (data_get($result, 'data.message') ?? 'Hotel booking created successfully.'),
            status: (int) ($result['status'] ?? 200),
            parsed: $parsed,
            payload: [
                'hotel_id' => $parsed['hotel_id'],
                'hotel_name' => data_get($hotel, 'name'),
                'hotel_room_id' => $parsed['hotel_room_id'],
                'check_in_date' => $parsed['check_in_date'],
                'check_out_date' => $parsed['check_out_date'],
                'total_persons' => $parsed['total_persons'],
                'payment_method' => $parsed['payment_method'],
                'total_cost' => $totalCost,
                'redirected_url' => $redirectUrl,
            ],
            endpoint: $result['endpoint'] ?? null,
            upstream: $result['data'] ?? null,
            inputMessage: $message,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $seatLayout
     * @param  array<int, string>  $requestedSeats
     * @return array<int, array<string, mixed>>
     */
    private function selectTripSeats(array $seatLayout, array $requestedSeats): array
    {
        $selected = [];

        foreach ($seatLayout as $seat) {
            $seatNumber = Str::upper((string) ($seat['seat_number'] ?? ''));
            $isAvailable = (int) ($seat['is_available'] ?? 0) === 1;

            if ($seatNumber !== '' && in_array($seatNumber, $requestedSeats, true) && $isAvailable) {
                $selected[] = $seat;
            }
        }

        return $selected;
    }

    /**
     * @param  array<string, mixed>  $room
     */
    private function calculateHotelTotalCost(array $room, CarbonImmutable $checkIn, CarbonImmutable $checkOut): ?float
    {
        $prices = array_values(array_filter($room['prices'] ?? [], 'is_array'));
        $cursor = $checkIn;
        $total = 0.0;

        while ($cursor->lessThan($checkOut)) {
            $matched = Arr::first($prices, function (array $price) use ($cursor): bool {
                $start = $this->safeDate((string) ($price['season_start'] ?? ''));
                $end = $this->safeDate((string) ($price['season_end'] ?? ''));

                return $start !== null && $end !== null && $cursor->between($start, $end, true);
            });

            if (! is_array($matched)) {
                return null;
            }

            $nightly = (float) ($matched['price_per_night'] ?? 0);

            if ($nightly <= 0) {
                return null;
            }

            $total += $nightly;
            $cursor = $cursor->addDay();
        }

        return round($total, 2);
    }

    private function safeDate(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array{
     *     action: 'book_trip'|'book_hotel'|null,
     *     trip_id: int|null,
     *     trip_name: string|null,
     *     seat_numbers: array<int, string>,
     *     hotel_id: int|null,
     *     hotel_room_id: int|null,
     *     check_in_date: string|null,
     *     check_out_date: string|null,
     *     total_persons: int|null,
     *     payment_method: string|null,
     *     payment_reference: string|null,
     *     needs_more_information: bool
     * }  $parsed
     * @return array<string, mixed>
     */
    private function hotelPayload(array $parsed, float $totalCost): array
    {
        $payload = [
            'hotel_id' => (int) $parsed['hotel_id'],
            'hotel_room_id' => (int) $parsed['hotel_room_id'],
            'check_in_date' => $parsed['check_in_date'],
            'check_out_date' => $parsed['check_out_date'],
            'total_persons' => (int) $parsed['total_persons'],
            'total_cost' => $totalCost,
            'payment_method' => (string) $parsed['payment_method'],
        ];

        if ($parsed['payment_method'] === 'card' && $parsed['payment_reference'] !== null) {
            $payload['card'] = $parsed['payment_reference'];
        }

        if ($parsed['payment_method'] === 'bkash' && $parsed['payment_reference'] !== null) {
            $payload['bkash'] = $parsed['payment_reference'];
        }

        if ($parsed['payment_method'] === 'nagad' && $parsed['payment_reference'] !== null) {
            $payload['nagad'] = $parsed['payment_reference'];
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function tripPaymentPayload(string $paymentMethod, ?string $paymentReference, float $amount): array
    {
        $payload = [
            'amount' => $amount,
            'payment_method' => $paymentMethod,
        ];

        if ($paymentMethod === 'card' && $paymentReference !== null) {
            $payload['card'] = $paymentReference;
        }

        if ($paymentMethod === 'bkash' && $paymentReference !== null) {
            $payload['bkash'] = $paymentReference;
        }

        if ($paymentMethod === 'nagad' && $paymentReference !== null) {
            $payload['nagad'] = $paymentReference;
        }

        return $payload;
    }

    /**
     * @param  array{
     *     action: 'book_trip'|'book_hotel'|null,
     *     trip_id: int|null,
     *     seat_numbers: array<int, string>,
     *     hotel_id: int|null,
     *     hotel_room_id: int|null,
     *     check_in_date: string|null,
     *     check_out_date: string|null,
     *     total_persons: int|null,
     *     payment_method: string|null,
     *     payment_reference: string|null,
     *     needs_more_information: bool
     * }  $parsed
     * @return array<string, mixed>
     */
    private function needsMoreInformationResponse(array $parsed, string $message): array
    {
        $type = $parsed['action'] === 'book_trip' ? 'trip' : ($parsed['action'] === 'book_hotel' ? 'hotel' : 'booking');
        $html = $this->bookingChatHtmlRenderer->renderNeedsMoreInformation(
            title: ucfirst($type).' Booking Needs More Detail',
            message: $type === 'trip'
                ? 'Share the trip id or trip name, seat numbers, and payment method to create the booking.'
                : 'Share the hotel id, room id, check-in/check-out dates, guest count, and payment method to create the booking.',
            details: $this->detailSnapshot($parsed),
        );

        return [
            'status' => 200,
            'action' => $parsed['action'] ?? 'create_booking',
            'message' => $type === 'trip'
                ? 'Please share the trip id or trip name, seat numbers, and payment method so the trip booking can be created.'
                : 'Please share the hotel id, room id, stay dates, guest count, and payment method so the hotel booking can be created.',
            'authentication' => [
                'synced' => true,
                'method' => 'forwarded_bearer_token',
            ],
            'input' => [
                'message' => $message,
            ],
            'booking' => [
                'type' => $type,
                'created' => false,
                'needs_more_information' => true,
                'payload' => $this->bookingPayloadSnapshot($parsed),
            ],
            'html' => $html,
        ];
    }

    /**
     * @param  array{
     *     action: 'book_trip'|'book_hotel'|null,
     *     trip_id: int|null,
     *     trip_name: string|null,
     *     seat_numbers: array<int, string>,
     *     hotel_id: int|null,
     *     hotel_room_id: int|null,
     *     check_in_date: string|null,
     *     check_out_date: string|null,
     *     total_persons: int|null,
     *     payment_method: string|null,
     *     payment_reference: string|null,
     *     needs_more_information: bool
     * }  $parsed
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function successResponse(
        string $type,
        string $message,
        int $status,
        array $parsed,
        array $payload,
        ?string $endpoint,
        mixed $upstream,
        string $inputMessage,
    ): array {
        $details = $this->successDetails($type, $payload);
        $redirectUrl = isset($payload['redirected_url']) && is_string($payload['redirected_url'])
            ? $payload['redirected_url']
            : null;
        $paymentLabel = $this->paymentMethodLabel(
            isset($payload['payment_method']) && is_string($payload['payment_method'])
                ? $payload['payment_method']
                : null,
        );
        $chatMessage = $this->bookingSuccessMessage(
            type: $type,
            providerMessage: $message,
            paymentLabel: $paymentLabel,
            redirectUrl: $redirectUrl,
        );
        $html = $this->bookingChatHtmlRenderer->renderCreated(
            title: ucfirst($type).' Booking Created',
            message: $chatMessage,
            details: $details,
            redirectUrl: $redirectUrl,
            paymentLabel: $paymentLabel,
        );

        return [
            'status' => $status,
            'action' => $parsed['action'],
            'message' => $chatMessage,
            'authentication' => [
                'synced' => true,
                'method' => 'forwarded_bearer_token',
            ],
            'input' => [
                'message' => $inputMessage,
            ],
            'booking' => [
                'type' => $type,
                'created' => true,
                'payload' => $payload,
                'endpoint' => $endpoint,
                'upstream' => $upstream,
            ],
            'html' => $html,
        ];
    }

    /**
     * @param  array{
     *     action: 'book_trip'|'book_hotel'|null,
     *     trip_id: int|null,
     *     seat_numbers: array<int, string>,
     *     hotel_id: int|null,
     *     hotel_room_id: int|null,
     *     check_in_date: string|null,
     *     check_out_date: string|null,
     *     total_persons: int|null,
     *     payment_method: string|null,
     *     payment_reference: string|null,
     *     needs_more_information: bool
     * }  $parsed
     * @param  array<string, scalar|null>  $extra
     * @return array<string, mixed>
     */
    private function failureResponse(
        string $type,
        string $message,
        int $status,
        array $parsed,
        array $extra,
        ?string $endpoint,
        mixed $upstream,
        string $inputMessage,
    ): array {
        $html = $this->bookingChatHtmlRenderer->renderFailure(
            title: ucfirst($type).' Booking Failed',
            message: $message,
            details: $extra,
        );

        return [
            'status' => $status,
            'action' => $parsed['action'] ?? 'create_booking',
            'message' => $message,
            'authentication' => [
                'synced' => true,
                'method' => 'forwarded_bearer_token',
            ],
            'input' => [
                'message' => $inputMessage,
            ],
            'booking' => [
                'type' => $type,
                'created' => false,
                'payload' => $this->bookingPayloadSnapshot($parsed),
                'endpoint' => $endpoint,
                'upstream' => $upstream,
            ],
            'html' => $html,
        ];
    }

    /**
     * @param  array{
     *     action: 'book_trip'|'book_hotel'|null,
     *     trip_id: int|null,
     *     seat_numbers: array<int, string>,
     *     hotel_id: int|null,
     *     hotel_room_id: int|null,
     *     check_in_date: string|null,
     *     check_out_date: string|null,
     *     total_persons: int|null,
     *     payment_method: string|null,
     *     payment_reference: string|null,
     *     needs_more_information: bool
     * }  $parsed
     * @return array<string, mixed>
     */
    private function bookingPayloadSnapshot(array $parsed): array
    {
        return [
            'trip_id' => $parsed['trip_id'],
            'trip_name' => $parsed['trip_name'],
            'seat_numbers' => $parsed['seat_numbers'],
            'hotel_id' => $parsed['hotel_id'],
            'hotel_room_id' => $parsed['hotel_room_id'],
            'check_in_date' => $parsed['check_in_date'],
            'check_out_date' => $parsed['check_out_date'],
            'total_persons' => $parsed['total_persons'],
            'payment_method' => $parsed['payment_method'],
        ];
    }

    /**
     * @param  array{
     *     action: 'book_trip'|'book_hotel'|null,
     *     trip_id: int|null,
     *     trip_name: string|null,
     *     seat_numbers: array<int, string>,
     *     hotel_id: int|null,
     *     hotel_room_id: int|null,
     *     check_in_date: string|null,
     *     check_out_date: string|null,
     *     total_persons: int|null,
     *     payment_method: string|null,
     *     payment_reference: string|null,
     *     needs_more_information: bool
     * }  $parsed
     * @return array<string, scalar|null>
     */
    private function detailSnapshot(array $parsed): array
    {
        return [
            'Trip ID' => $parsed['trip_id'],
            'Trip Name' => $parsed['trip_name'],
            'Seats' => $parsed['seat_numbers'] === [] ? null : implode(', ', $parsed['seat_numbers']),
            'Hotel ID' => $parsed['hotel_id'],
            'Room ID' => $parsed['hotel_room_id'],
            'Check In' => $parsed['check_in_date'],
            'Check Out' => $parsed['check_out_date'],
            'Guests' => $parsed['total_persons'],
            'Payment Method' => $parsed['payment_method'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, scalar|null>
     */
    private function successDetails(string $type, array $payload): array
    {
        if ($type === 'trip') {
            return [
                'Trip ID' => $payload['trip_id'] ?? null,
                'Trip Name' => $payload['trip_name'] ?? null,
                'Seats' => isset($payload['seat_numbers']) && is_array($payload['seat_numbers'])
                    ? implode(', ', $payload['seat_numbers'])
                    : null,
                'Payment Method' => $payload['payment_method'] ?? null,
                'Amount' => isset($payload['amount']) ? $this->formatMoney((float) $payload['amount']) : null,
            ];
        }

        return [
            'Hotel ID' => $payload['hotel_id'] ?? null,
            'Hotel Name' => $payload['hotel_name'] ?? null,
            'Room ID' => $payload['hotel_room_id'] ?? null,
            'Check In' => $payload['check_in_date'] ?? null,
            'Check Out' => $payload['check_out_date'] ?? null,
            'Guests' => $payload['total_persons'] ?? null,
            'Payment Method' => $payload['payment_method'] ?? null,
            'Total Cost' => isset($payload['total_cost']) ? $this->formatMoney((float) $payload['total_cost']) : null,
        ];
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function extractRedirectUrlFromResult(array $result): ?string
    {
        $paths = [
            'data.data.redirected_url',
            'data.data.redirect_url',
            'data.data.payment_url',
            'data.data.url',
            'data.data.redirectedUrl',
            'data.data.redirectUrl',
            'data.redirected_url',
            'data.redirect_url',
            'data.payment_url',
            'data.url',
            'data.redirectedUrl',
            'data.redirectUrl',
        ];

        foreach ($paths as $path) {
            $value = data_get($result, $path);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function paymentMethodLabel(?string $paymentMethod): ?string
    {
        if ($paymentMethod === null || trim($paymentMethod) === '') {
            return null;
        }

        return match (trim($paymentMethod)) {
            'bkash' => 'bKash',
            'nagad' => 'Nagad',
            'internet_banking' => 'internet banking',
            'card' => 'card',
            default => trim($paymentMethod),
        };
    }

    private function bookingSuccessMessage(
        string $type,
        string $providerMessage,
        ?string $paymentLabel,
        ?string $redirectUrl,
    ): string {
        if (! is_string($redirectUrl) || $redirectUrl === '') {
            return trim($providerMessage) !== ''
                ? trim($providerMessage)
                : ucfirst($type).' booking created successfully.';
        }

        $base = trim($providerMessage) !== ''
            ? rtrim(trim($providerMessage), " \t\n\r\0\x0B.!?")
            : ucfirst($type).' booking created successfully';

        $instruction = $paymentLabel !== null
            ? 'Complete the '.$paymentLabel.' payment using the payment link below.'
            : 'Complete the payment using the payment link below.';

        return $base.'. '.$instruction;
    }

    /**
     * @param  array{
     *     action: 'book_trip'|'book_hotel'|null,
     *     trip_id: int|null,
     *     trip_name: string|null,
     *     seat_numbers: array<int, string>,
     *     hotel_id: int|null,
     *     hotel_room_id: int|null,
     *     check_in_date: string|null,
     *     check_out_date: string|null,
     *     total_persons: int|null,
     *     payment_method: string|null,
     *     payment_reference: string|null,
     *     needs_more_information: bool
     * }  $parsed
     * @return array<string, mixed>
     */
    private function resolveTripForBooking(string $bearerToken, array $parsed): array
    {
        if ($parsed['trip_id'] !== null) {
            return [
                'error' => false,
                'trip_id' => $parsed['trip_id'],
                'trip_name' => $parsed['trip_name'],
            ];
        }

        if (! is_string($parsed['trip_name']) || trim($parsed['trip_name']) === '') {
            return [
                'error' => true,
                'status' => 422,
                'message' => 'Please tell me which trip you want to book.',
            ];
        }

        return $this->resolveTripFromName($bearerToken, (string) $parsed['trip_name']);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveTripFromName(string $bearerToken, string $tripName): array
    {
        $lastSection = null;

        foreach ($this->tripNameSearchTerms($tripName) as $searchTerm) {
            $search = $this->travelBookingApiClient->searchOverview($bearerToken, $searchTerm, ['trips']);
            $section = $search['trips'] ?? null;
            $lastSection = $section;

            if (! is_array($section) || (($section['error'] ?? true) === true)) {
                continue;
            }

            $items = array_values(array_filter(data_get($section, 'data.data', []), 'is_array'));
            $match = $this->bestTripMatch($items, $tripName)
                ?? $this->bestTripMatch($items, $searchTerm);
            $resolvedTripId = $this->extractTripIdentifier($match);

            if (! is_array($match) || $resolvedTripId === null) {
                continue;
            }

            return [
                'error' => false,
                'trip_id' => $resolvedTripId,
                'trip_name' => $this->tripDisplayName($match) ?? $tripName,
                'endpoint' => $section['endpoint'] ?? null,
            ];
        }

        return [
            'error' => true,
            'status' => is_array($lastSection) ? (int) ($lastSection['status'] ?? 422) : 422,
            'message' => is_array($lastSection) && (($lastSection['error'] ?? false) === true)
                ? ($lastSection['message'] ?? 'The trip could not be found for booking.')
                : 'I could not uniquely match that trip name. Please mention the exact trip name shown in the list.',
            'endpoint' => is_array($lastSection) ? ($lastSection['endpoint'] ?? null) : null,
            'upstream' => is_array($lastSection)
                ? (($lastSection['details']['response'] ?? $lastSection['data'] ?? null))
                : null,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function tripNameSearchTerms(string $tripName): array
    {
        $candidates = [];
        $trimmed = trim($tripName);
        $cleaned = $this->cleanTripNameForSearch($trimmed);

        foreach ([$trimmed, $cleaned] as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                $candidates[] = $candidate;
            }
        }

        if (preg_match('/\btrip\b\s+(.+)$/iu', $cleaned, $matches) === 1) {
            $afterTrip = $this->cleanTripNameForSearch((string) ($matches[1] ?? ''));

            if ($afterTrip !== '') {
                $candidates[] = $afterTrip;
            }
        }

        return array_values(array_unique(array_filter($candidates, static fn (string $value): bool => $value !== '')));
    }

    private function cleanTripNameForSearch(string $value): string
    {
        $cleaned = preg_replace('/#\s*\d+\b/u', ' ', $value) ?? $value;
        $cleaned = preg_replace('/\b\d{5,}\b/u', ' ', $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s+/', ' ', $cleaned) ?? $cleaned;

        return trim($cleaned, " \t\n\r\0\x0B,.;:-");
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>|null
     */
    private function bestTripMatch(array $items, string $tripName): ?array
    {
        $needle = $this->normalizeTripSearchText($tripName);

        if ($needle === '') {
            return count($items) === 1 ? $items[0] : null;
        }

        $exact = array_values(array_filter($items, function (array $item) use ($needle): bool {
            $headline = $this->tripDisplayName($item);

            return $headline !== null && $this->normalizeTripSearchText($headline) === $needle;
        }));

        if (count($exact) === 1) {
            return $exact[0];
        }

        if (count($items) === 1) {
            return $items[0];
        }

        $contains = array_values(array_filter($items, function (array $item) use ($needle): bool {
            $headline = $this->tripDisplayName($item);

            return $headline !== null && str_contains($this->normalizeTripSearchText($headline), $needle);
        }));

        return count($contains) === 1 ? $contains[0] : null;
    }

    /**
     * @param  array<string, mixed>|null  $item
     */
    private function extractTripIdentifier(?array $item): ?int
    {
        if (! is_array($item)) {
            return null;
        }

        foreach (['id', 'trip_id'] as $key) {
            $value = $item[$key] ?? null;

            if (is_int($value) && $value > 0) {
                return $value;
            }

            if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function tripDisplayName(array $item): ?string
    {
        foreach (['trip_name', 'name', 'title'] as $key) {
            $value = $item[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function normalizeTripSearchText(string $value): string
    {
        $normalized = Str::lower($this->cleanTripNameForSearch($value));
        $normalized = str_replace(["'", '`'], '', $normalized);
        $normalized = preg_replace('/[^a-z0-9]+/u', ' ', $normalized) ?? $normalized;

        return trim(preg_replace('/\s+/', ' ', $normalized) ?? $normalized);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function tripSummaryIsEmpty(array $summary): bool
    {
        if (($summary['error'] ?? true) === true) {
            return false;
        }

        $tripSummaries = array_values(array_filter(
            data_get($summary, 'data.data.tripSummaries', []),
            'is_array',
        ));
        $seatLayout = array_values(array_filter(
            data_get($summary, 'data.data.seat_layout', []),
            'is_array',
        ));

        return $tripSummaries === [] && $seatLayout === [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $seatLayout
     * @return array<int, string>
     */
    private function availableSeatNumbers(array $seatLayout): array
    {
        $available = [];

        foreach ($seatLayout as $seat) {
            if ((int) ($seat['is_available'] ?? 0) !== 1) {
                continue;
            }

            $seatNumber = Str::upper(trim((string) ($seat['seat_number'] ?? '')));

            if ($seatNumber !== '') {
                $available[] = $seatNumber;
            }
        }

        return array_values(array_unique($available));
    }

    /**
     * @param  array<int, string>  $availableSeatNumbers
     */
    private function seatPreview(array $availableSeatNumbers, int $limit = 20): string
    {
        if ($availableSeatNumbers === []) {
            return 'No seats are currently available.';
        }

        $preview = array_slice($availableSeatNumbers, 0, $limit);
        $text = implode(', ', $preview);

        if (count($availableSeatNumbers) > $limit) {
            $text .= ', ...';
        }

        return $text;
    }

    /**
     * @param  array{
     *     action: 'book_trip'|'book_hotel'|null,
     *     trip_id: int|null,
     *     trip_name: string|null,
     *     seat_numbers: array<int, string>,
     *     hotel_id: int|null,
     *     hotel_room_id: int|null,
     *     check_in_date: string|null,
     *     check_out_date: string|null,
     *     total_persons: int|null,
     *     payment_method: string|null,
     *     payment_reference: string|null,
     *     needs_more_information: bool
     * }  $parsed
     * @param  array<string, mixed>  $trip
     * @param  array<int, array<string, mixed>>  $seatLayout
     * @param  array<int, string>  $availableSeatNumbers
     * @return array<string, mixed>
     */
    private function seatSelectionResponse(
        array $parsed,
        array $trip,
        array $seatLayout,
        array $availableSeatNumbers,
        ?string $endpoint,
        string $inputMessage,
    ): array {
        $tripName = $parsed['trip_name'] ?? data_get($trip, 'trip_name') ?? 'Selected Trip';
        $message = $availableSeatNumbers === []
            ? 'No seats are currently available for this trip.'
            : 'These seats are currently available for this trip. Reply with the seat numbers you want to reserve.';

        $details = [
            'Trip' => $tripName,
            'Trip ID' => $parsed['trip_id'],
            'Available Seats' => $this->seatPreview($availableSeatNumbers, 28),
            'How To Reply' => 'book the '.$tripName.' trip, seats are A1,A2',
        ];

        $html = $this->bookingChatHtmlRenderer->renderNeedsMoreInformation(
            title: 'Choose Trip Seats',
            message: $message,
            details: $details,
        );

        return [
            'status' => 200,
            'action' => $parsed['action'],
            'message' => $message,
            'authentication' => [
                'synced' => true,
                'method' => 'forwarded_bearer_token',
            ],
            'input' => [
                'message' => $inputMessage,
            ],
            'booking' => [
                'type' => 'trip',
                'created' => false,
                'needs_more_information' => true,
                'payload' => $this->bookingPayloadSnapshot($parsed),
                'available_seat_numbers' => $availableSeatNumbers,
                'seat_information' => $this->seatInformation($seatLayout),
                'endpoint' => $endpoint,
            ],
            'html' => $html,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $seatLayout
     * @return array<int, array<string, mixed>>
     */
    private function seatInformation(array $seatLayout): array
    {
        return array_values(array_map(function (array $seat): array {
            $seatNumber = Str::upper(trim((string) ($seat['seat_number'] ?? '')));

            return [
                'trip_id' => isset($seat['trip_id']) && is_numeric((string) $seat['trip_id'])
                    ? (int) $seat['trip_id']
                    : null,
                'seat_id' => isset($seat['seat_id']) && is_numeric((string) $seat['seat_id'])
                    ? (int) $seat['seat_id']
                    : null,
                'seat_number' => $seatNumber !== '' ? $seatNumber : null,
                'vehicle_name' => is_scalar($seat['vehicle_name'] ?? null) && trim((string) $seat['vehicle_name']) !== ''
                    ? trim((string) $seat['vehicle_name'])
                    : null,
                'is_available' => (int) ($seat['is_available'] ?? 0) === 1,
            ];
        }, $seatLayout));
    }

    /**
     * @param  array{
     *     action: 'book_trip'|'book_hotel'|null,
     *     trip_id: int|null,
     *     trip_name: string|null,
     *     seat_numbers: array<int, string>,
     *     hotel_id: int|null,
     *     hotel_room_id: int|null,
     *     check_in_date: string|null,
     *     check_out_date: string|null,
     *     total_persons: int|null,
     *     payment_method: string|null,
     *     payment_reference: string|null,
     *     needs_more_information: bool
     * }  $parsed
     * @param  array<string, mixed>  $trip
     * @param  array<int, string>  $selectedSeats
     * @return array<string, mixed>
     */
    private function paymentMethodResponse(
        array $parsed,
        array $trip,
        array $selectedSeats,
        float $amount,
        string $inputMessage,
    ): array {
        $tripName = $parsed['trip_name'] ?? data_get($trip, 'trip_name') ?? 'Selected Trip';
        $html = $this->bookingChatHtmlRenderer->renderNeedsMoreInformation(
            title: 'Choose Payment Method',
            message: 'Your seats are selected. Send the payment method to finish the booking.',
            details: [
                'Trip' => $tripName,
                'Seats' => implode(', ', $selectedSeats),
                'Amount' => $this->formatMoney($amount),
                'How To Reply' => 'book the '.$tripName.' trip, seats are '.implode(',', $selectedSeats).', payment by bkash 01700000000',
            ],
        );

        return [
            'status' => 200,
            'action' => $parsed['action'],
            'message' => 'Please send the payment method to finish the trip booking. Use card, bkash, nagad, or internet banking.',
            'authentication' => [
                'synced' => true,
                'method' => 'forwarded_bearer_token',
            ],
            'input' => [
                'message' => $inputMessage,
            ],
            'booking' => [
                'type' => 'trip',
                'created' => false,
                'needs_more_information' => true,
                'payload' => $this->bookingPayloadSnapshot($parsed),
                'selected_seat_numbers' => $selectedSeats,
                'amount' => $amount,
            ],
            'html' => $html,
        ];
    }

    /**
     * @param  array{
     *     action: 'book_trip'|'book_hotel'|null,
     *     trip_id: int|null,
     *     trip_name: string|null,
     *     seat_numbers: array<int, string>,
     *     hotel_id: int|null,
     *     hotel_room_id: int|null,
     *     check_in_date: string|null,
     *     check_out_date: string|null,
     *     total_persons: int|null,
     *     payment_method: string|null,
     *     payment_reference: string|null,
     *     needs_more_information: bool
     * }  $parsed
     * @param  array<string, mixed>  $trip
     * @param  array<int, string>  $selectedSeats
     * @return array<string, mixed>
     */
    private function paymentReferenceResponse(
        array $parsed,
        array $trip,
        array $selectedSeats,
        float $amount,
        string $inputMessage,
    ): array {
        $tripName = $parsed['trip_name'] ?? data_get($trip, 'trip_name') ?? 'Selected Trip';
        $method = (string) $parsed['payment_method'];
        $exampleReference = $method === 'card' ? '42424242' : '01700000000';
        $html = $this->bookingChatHtmlRenderer->renderNeedsMoreInformation(
            title: 'Add Payment Details',
            message: 'The trip and seats are ready. Send the payment account or card reference to complete the booking.',
            details: [
                'Trip' => $tripName,
                'Seats' => implode(', ', $selectedSeats),
                'Payment Method' => $method,
                'Amount' => $this->formatMoney($amount),
                'How To Reply' => 'book the '.$tripName.' trip, seats are '.implode(',', $selectedSeats).', payment by '.$method.' '.$exampleReference,
            ],
        );

        return [
            'status' => 200,
            'action' => $parsed['action'],
            'message' => 'Please send the '.$method.' number or card reference to finish the trip booking.',
            'authentication' => [
                'synced' => true,
                'method' => 'forwarded_bearer_token',
            ],
            'input' => [
                'message' => $inputMessage,
            ],
            'booking' => [
                'type' => 'trip',
                'created' => false,
                'needs_more_information' => true,
                'payload' => $this->bookingPayloadSnapshot($parsed),
                'selected_seat_numbers' => $selectedSeats,
                'amount' => $amount,
            ],
            'html' => $html,
        ];
    }
}
