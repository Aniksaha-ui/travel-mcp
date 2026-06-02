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
#[Instructions('Provides authenticated trip, package, and hotel search tools for the travel platform. Every tool forwards the caller bearer token to the remote TravelBooking API, and the overview tool can fetch all three datasets together for a single location.')]
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
