<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\SearchHotelsTool;
use App\Mcp\Tools\SearchPackagesTool;
use App\Mcp\Tools\SearchTravelOverviewTool;
use App\Mcp\Tools\SearchTripsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('travel-server')]
#[Version('1.0.0')]
#[Instructions('Provides authenticated trip, package, and hotel search tools for the travel platform. Every tool forwards the caller bearer token to the remote TravelBooking API, and the overview tool can fetch all three datasets together for a single location. When presenting tool results to the user, prefer attractive and professional HTML with clear section headings, a short natural-language summary, result counts such as "There are 5 trips", and numbered detail blocks like "Trip 1". Use tables for quick comparison when helpful, but keep the overall response conversational and human-readable rather than dumping raw JSON or flat field lists.')]
class TravelServer extends Server
{
    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<\Laravel\Mcp\Server\Tool>>
     */
    protected array $tools = [
        SearchTripsTool::class,
        SearchPackagesTool::class,
        SearchHotelsTool::class,
        SearchTravelOverviewTool::class,
    ];
}
