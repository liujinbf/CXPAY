<?php

declare(strict_types=1);

use CloudControl\Shared\Http\HealthController;
use Webman\Route;

Route::disableDefaultRoute();
Route::get('/health', [HealthController::class, '__invoke']);
