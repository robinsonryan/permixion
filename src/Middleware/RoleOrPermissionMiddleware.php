<?php

namespace RobinsonRyan\Permixion\Middleware;

use Closure;
use Illuminate\Http\Request;
use RobinsonRyan\Permixion\Exceptions\UnauthorizedException;

class RoleOrPermissionMiddleware
{
    public function handle(Request $request, Closure $next, string ...$rolesOrPermissions): mixed
    {
        $user = $request->user();

        if (! $user) {
            throw UnauthorizedException::notLoggedIn();
        }

        if (! method_exists($user, 'hasRole') || ! method_exists($user, 'hasPermissionTo')) {
            throw UnauthorizedException::missingTrait();
        }

        $scope = app('permixion')->resolveCurrentScope();

        foreach ($rolesOrPermissions as $roleOrPermission) {
            if ($user->hasRole($roleOrPermission, $scope)) {
                return $next($request);
            }

            if ($user->hasPermissionTo($roleOrPermission, $scope)) {
                return $next($request);
            }
        }

        throw UnauthorizedException::forRolesOrPermissions(array_values($rolesOrPermissions));
    }
}
