<?php

declare(strict_types=1);

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Sunrice\Commerce\Http\Middleware\BindAdminModels;
use Sunrice\Commerce\Http\Middleware\ScopeAdminOrders;
use Sunrice\CommerceBiteship\Http\AdminController;

/* Under Sunrice's admin path and middleware: names sunrice.admin.commerce-biteship.* */

$routes = Route::prefix('commerce/orders/{order}/biteship')->whereNumber('order')->name('commerce-biteship.');

// As commerce's own order pages (sunrice/commerce 0.3+): models are resolved after the panel has
// signed the user in, and an order outside the staff member's scope (Commerce::scopeAdminOrders())
// answers 404. Older commerce has neither.
if (class_exists(ScopeAdminOrders::class) && class_exists(BindAdminModels::class)) {
    $routes = $routes->withoutMiddleware(SubstituteBindings::class)->middleware([BindAdminModels::class, ScopeAdminOrders::class]);
}

$routes->group(function (): void {
    Route::post('book', [AdminController::class, 'book'])->name('book');
    Route::post('{shipment}/cancel', [AdminController::class, 'cancel'])->whereNumber('shipment')->name('cancel');
    Route::post('{shipment}/refresh', [AdminController::class, 'refresh'])->whereNumber('shipment')->name('refresh');
    Route::get('{shipment}/label', [AdminController::class, 'label'])->whereNumber('shipment')->name('label');
});
