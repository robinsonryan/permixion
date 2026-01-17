<?php

namespace RobinsonRyan\Permixion\Contracts;

use Illuminate\Support\Collection;
use RobinsonRyan\Permixion\Models\Permission;
use RobinsonRyan\Permixion\Models\Role;
use RobinsonRyan\Taxon\Contracts\Scope;

interface HasPermissions
{
    // Role assignment
    public function assignRole(string|Role|array $roles, ?Scope $scope = null): static;

    public function removeRole(string|Role $role, ?Scope $scope = null): static;

    public function syncRoles(array $roles, ?Scope $scope = null): static;

    // Role checks
    public function hasRole(string|Role $role, ?Scope $scope = null): bool;

    public function hasAnyRole(array $roles, ?Scope $scope = null): bool;

    public function hasAllRoles(array $roles, ?Scope $scope = null): bool;

    // Role retrieval
    public function getRoles(?Scope $scope = null): Collection;

    public function getRoleNames(?Scope $scope = null): array;

    // Permission checks
    public function hasPermissionTo(string|Permission $permission, ?Scope $scope = null): bool;

    public function hasAnyPermission(array $permissions, ?Scope $scope = null): bool;

    public function hasAllPermissions(array $permissions, ?Scope $scope = null): bool;

    // Direct permissions
    public function givePermissionTo(string|Permission|array $permissions, ?Scope $scope = null): static;

    public function revokePermissionTo(string|Permission|array $permissions, ?Scope $scope = null): static;

    public function getDirectPermissions(?Scope $scope = null): Collection;

    public function getAllPermissions(?Scope $scope = null): Collection;
}
