<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\MatchController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/matches', MatchController::class);
});
