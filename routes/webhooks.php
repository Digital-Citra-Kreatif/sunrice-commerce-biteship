<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Sunrice\CommerceBiteship\Http\WebhookController;

/* Biteship's webhooks: stateless (no session, no CSRF), verified by fetching the booking. */

Route::post((string) config('commerce-biteship.webhook_path', 'biteship/webhook'), WebhookController::class)
    ->middleware(['throttle:commerce-webhooks'])
    ->name('commerce-biteship.webhook');
