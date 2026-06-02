<?php

use App\Mcp\Servers\TravelServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/travel', TravelServer::class);
    // ->middleware(['auth:sanctum']);
