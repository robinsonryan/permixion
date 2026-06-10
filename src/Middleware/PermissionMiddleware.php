<?php

namespace RobinsonRyan\Permixion\Middleware;

use Closure;
use Illuminate\Http\Request;
use RobinsonRyan\Permixion\Exceptions\UnauthorizedException;

class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions): mixed
    {
        $user = $request->user();

        if (! $user) {
            throw UnauthorizedException::notLoggedIn();
        }

        if (! method_exists($user, 'hasAnyPermission')) {
            throw UnauthorizedException::missingTrait();
        }

        $scope = app('permixion')->resolveCurrentScope();

        if (! $user->hasAnyPermission($permissions, $scope)) {
            throw UnauthorizedException::forPermissions(array_values($permissions));
        }

        return $next($request);
    }
}
