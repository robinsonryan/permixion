<?php

namespace RobinsonRyan\Permixion\Middleware;

use Closure;
use Illuminate\Http\Request;
use RobinsonRyan\Permixion\Exceptions\UnauthorizedException;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        $user = $request->user();

        if (! $user) {
            throw UnauthorizedException::notLoggedIn();
        }

        if (! method_exists($user, 'hasAnyRole')) {
            throw UnauthorizedException::missingTrait();
        }

        $scope = app('permixion')->resolveCurrentScope();

        if (! $user->hasAnyRole($roles, $scope)) {
            throw UnauthorizedException::forRoles($roles);
        }

        return $next($request);
    }
}
