<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Sunrice\CommerceBiteship\Http\AdminController;

/* Under Sunrice's admin path and middleware: names sunrice.admin.commerce-biteship.* */

Route::prefix('commerce/orders/{order}/biteship')->whereNumber('order')->name('commerce-biteship.')->group(function (): void {
    Route::post('book', [AdminController::class, 'book'])->name('book');
    Route::post('{shipment}/cancel', [AdminController::class, 'cancel'])->whereNumber('shipment')->name('cancel');
    Route::post('{shipment}/refresh', [AdminController::class, 'refresh'])->whereNumber('shipment')->name('refresh');
    Route::get('{shipment}/label', [AdminController::class, 'label'])->whereNumber('shipment')->name('label');
});
