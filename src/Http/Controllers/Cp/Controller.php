<?php

namespace Goldnead\StatamicInsights\Http\Controllers\Cp;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

/**
 * Authorisation lives here, not in route middleware.
 *
 * `$user->can()` and not `hasPermission()`/`isSuper()`: Statamic registers a
 * `Gate::after` hook that resolves the Statamic user through `User::fromUser()`
 * and short-circuits super users. `can()` is therefore correct for both user
 * repositories, while the Statamic-specific methods fatal on an Eloquent-driver
 * site where the authenticated user is a bare `App\Models\User`.
 */
abstract class Controller extends BaseController
{
    protected function authorizeOrFail(Request $request, string $permission): void
    {
        if (! (bool) $request->user()?->can($permission)) {
            abort(403);
        }
    }
}
