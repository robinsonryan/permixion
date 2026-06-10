<?php

declare(strict_types=1);

namespace RobinsonRyan\Permixion\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \RobinsonRyan\Permixion\Models\Role createRole(string $name, array<int, string> $permissions = [])
 * @method static \RobinsonRyan\Permixion\Models\Role|null findRole(string $name)
 * @method static \RobinsonRyan\Permixion\Models\Role findRoleOrFail(string $name)
 * @method static bool roleExists(string $name)
 * @method static array<int, \RobinsonRyan\Permixion\Models\Role> getAllRoles()
 * @method static bool deleteRole(string $name)
 * @method static \RobinsonRyan\Permixion\Models\Permission createPermission(string $name)
 * @method static \RobinsonRyan\Permixion\Models\Permission|null findPermission(string $name)
 * @method static \RobinsonRyan\Permixion\Models\Permission findPermissionOrFail(string $name)
 * @method static bool permissionExists(string $name)
 * @method static array<int, \RobinsonRyan\Permixion\Models\Permission> getAllPermissions()
 * @method static bool deletePermission(string $name)
 * @method static array<int, string> getPermissionsForRole(string $roleName)
 * @method static bool permissionMatches(string $permission, string $pattern)
 * @method static bool userHasPermission(\Illuminate\Contracts\Auth\Access\Authorizable $user, string $permission, ?\RobinsonRyan\Taxon\Contracts\Scope $scope = null)
 * @method static \RobinsonRyan\Taxon\Contracts\Scope|null resolveCurrentScope()
 * @method static void setCurrentScope(?\RobinsonRyan\Taxon\Contracts\Scope $scope)
 * @method static \RobinsonRyan\Taxon\Models\Tag rolesCategory()
 * @method static \RobinsonRyan\Taxon\Models\Tag permissionsCategory()
 * @method static void clearCache()
 *
 * @see \RobinsonRyan\Permixion\Permixion
 */
class Permixion extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \RobinsonRyan\Permixion\Permixion::class;
    }
}
