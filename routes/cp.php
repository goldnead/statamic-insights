<?php

use Goldnead\StatamicInsights\Http\Controllers\Cp\RevenueController;
use Illuminate\Support\Facades\Route;

/*
 * No `can:` middleware here, deliberately. Statamic already puts CP routes
 * behind its own auth group, and the permission is checked in the controller
 * so that both user repositories resolve the same way — see the base
 * Controller for why `$user->can()` is the only form that works on an
 * Eloquent-driver site.
 *
 * The route is registered unconditionally. A nav item resolves its target
 * through `cp_route()` while the navigation is built, on every CP page — a
 * conditionally registered route behind an unconditional nav item takes the
 * whole Control Panel down with a RouteNotFoundException.
 */
Route::prefix('insights')->name('insights.')->group(function () {
    Route::get('/', [RevenueController::class, 'index'])->name('revenue');
});
