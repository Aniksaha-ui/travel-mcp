<?php

use App\Http\Controllers\Api\TravelChatController;
use Illuminate\Support\Facades\Route;

Route::post('/chat', TravelChatController::class);
