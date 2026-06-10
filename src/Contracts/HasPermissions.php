<?php

declare(strict_types=1);

namespace RobinsonRyan\Permixion\Contracts;

use Illuminate\Support\Collection;
use RobinsonRyan\Permixion\Models\Permission;
use RobinsonRyan\Permixion\Models\Role;
use RobinsonRyan\Taxon\Contracts\Scope;

interface HasPermissions
{
    // Role assignment

    /**
     * @param  string|Role|array<int, string|Role>  $roles
     */
    public function assignRole(string|Role|array $roles, ?Scope $scope = null): static;

    public function removeRole(string|Role $role, ?Scope $scope = null): static;

    /**
     * @param  array<int, string|Role>  $roles
     */
    public function syncRoles(array $roles, ?Scope $scope = null): static;

    // Role checks
    public function hasRole(string|Role $role, ?Scope $scope = null): bool;

    /**
     * @param  array<int, string|Role>  $roles
     */
    public function hasAnyRole(array $roles, ?Scope $scope = null): bool;

    /**
     * @param  array<int, string|Role>  $roles
     */
    public function hasAllRoles(array $roles, ?Scope $scope = null): bool;

    // Role retrieval

    /**
     * @return Collection<int, Role>
     */
    public function getRoles(?Scope $scope = null): Collection;

    /**
     * @return array<int, string>
     */
    public function getRoleNames(?Scope $scope = null): array;

    // Permission checks
    public function hasPermissionTo(string|Permission $permission, ?Scope $scope = null): bool;

    /**
     * @param  array<int, string|Permission>  $permissions
     */
    public function hasAnyPermission(array $permissions, ?Scope $scope = null): bool;

    /**
     * @param  array<int, string|Permission>  $permissions
     */
    public function hasAllPermissions(array $permissions, ?Scope $scope = null): bool;

    // Direct permissions

    /**
     * @param  string|Permission|array<int, string|Permission>  $permissions
     */
    public function givePermissionTo(string|Permission|array $permissions, ?Scope $scope = null): static;

    /**
     * @param  string|Permission|array<int, string|Permission>  $permissions
     */
    public function revokePermissionTo(string|Permission|array $permissions, ?Scope $scope = null): static;

    /**
     * @return Collection<int, Permission>
     */
    public function getDirectPermissions(?Scope $scope = null): Collection;

    /**
     * @return Collection<int, Permission>
     */
    public function getAllPermissions(?Scope $scope = null): Collection;
}
