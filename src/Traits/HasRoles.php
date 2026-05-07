<?php

namespace RobinsonRyan\Permixion\Traits;

use Illuminate\Support\Collection;
use RobinsonRyan\Permixion\Models\Permission;
use RobinsonRyan\Permixion\Models\Role;
use RobinsonRyan\Taxon\Contracts\Scope;

/**
 * HasRoles uses Tag.name (verbatim) as the identity for roles and
 * permissions. It deliberately bypasses Taxon's HasTags helpers
 * (addTag/hasTagIn/etc.) because those slug their input via Str::slug,
 * which would mangle delimiter-bearing identifiers like 'posts.create'.
 */
trait HasRoles
{
    /*
    |--------------------------------------------------------------------------
    | Role Assignment
    |--------------------------------------------------------------------------
    */

    /**
     * @param  string|Role|array<int, string|Role>  $roles
     */
    public function assignRole(string|Role|array $roles, ?Scope $scope = null): static
    {
        $scope = $scope ?? app('permixion')->resolveCurrentScope();
        $roles = is_array($roles) ? $roles : [$roles];

        foreach ($roles as $role) {
            $roleName = $role instanceof Role ? $role->name : $role;
            app('permixion')->attachRoleToUser($this, $roleName, $scope);
        }

        return $this;
    }

    public function removeRole(string|Role $role, ?Scope $scope = null): static
    {
        $scope = $scope ?? app('permixion')->resolveCurrentScope();
        $roleName = $role instanceof Role ? $role->name : $role;

        app('permixion')->detachRoleFromUser($this, $roleName, $scope);

        return $this;
    }

    /**
     * @param  array<int, string|Role>  $roles
     */
    public function syncRoles(array $roles, ?Scope $scope = null): static
    {
        $scope = $scope ?? app('permixion')->resolveCurrentScope();

        app('permixion')->detachAllUserRoles($this, $scope);

        foreach ($roles as $role) {
            $roleName = $role instanceof Role ? $role->name : $role;
            app('permixion')->attachRoleToUser($this, $roleName, $scope);
        }

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Role Checks
    |--------------------------------------------------------------------------
    */

    public function hasRole(string|Role $role, ?Scope $scope = null): bool
    {
        $scope = $scope ?? app('permixion')->resolveCurrentScope();
        $roleName = $role instanceof Role ? $role->name : $role;

        return app('permixion')->userHasRole($this, $roleName, $scope);
    }

    /**
     * @param  array<int, string|Role>  $roles
     */
    public function hasAnyRole(array $roles, ?Scope $scope = null): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role, $scope)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string|Role>  $roles
     */
    public function hasAllRoles(array $roles, ?Scope $scope = null): bool
    {
        foreach ($roles as $role) {
            if (! $this->hasRole($role, $scope)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, string|Role>  $roles
     */
    public function hasExactRoles(array $roles, ?Scope $scope = null): bool
    {
        $currentRoles = $this->getRoleNames($scope);

        $expected = array_map(
            fn ($r) => $r instanceof Role ? $r->name : $r,
            $roles,
        );

        if (count($currentRoles) !== count($expected)) {
            return false;
        }

        return empty(array_diff($currentRoles, $expected))
            && empty(array_diff($expected, $currentRoles));
    }

    /*
    |--------------------------------------------------------------------------
    | Role Retrieval
    |--------------------------------------------------------------------------
    */

    public function getRoles(?Scope $scope = null): Collection
    {
        $scope = $scope ?? app('permixion')->resolveCurrentScope();
        $categoryId = app('permixion')->rolesCategory()->id;
        $pivotTable = config('taxon.tables.taggables', 'taggables');

        $query = $this->tags()->where('parent_id', $categoryId);

        if ($scope !== null) {
            $query->where("{$pivotTable}.scope_type", $scope->getScopeType())
                ->where("{$pivotTable}.scope_id", $scope->getScopeId());
        } else {
            $query->whereNull("{$pivotTable}.scope_type")
                ->whereNull("{$pivotTable}.scope_id");
        }

        return $query->get()->map(fn ($tag) => new Role($tag));
    }

    /**
     * @return array<int, string>
     */
    public function getRoleNames(?Scope $scope = null): array
    {
        return app('permixion')->getUserRoleNames($this, $scope);
    }

    /*
    |--------------------------------------------------------------------------
    | Permission Checks
    |--------------------------------------------------------------------------
    */

    public function hasPermissionTo(string|Permission $permission, ?Scope $scope = null): bool
    {
        $scope = $scope ?? app('permixion')->resolveCurrentScope();
        $permissionName = $permission instanceof Permission ? $permission->name : $permission;

        if (config('permixion.super_admin.enabled')) {
            $superAdminRole = config('permixion.super_admin.role');
            if ($superAdminRole !== null && $this->hasRole($superAdminRole, $scope)) {
                return true;
            }
        }

        return app('permixion')->userHasPermission($this, $permissionName, $scope);
    }

    /**
     * @param  array<int, string|Permission>  $permissions
     */
    public function hasAnyPermission(array $permissions, ?Scope $scope = null): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermissionTo($permission, $scope)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string|Permission>  $permissions
     */
    public function hasAllPermissions(array $permissions, ?Scope $scope = null): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->hasPermissionTo($permission, $scope)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Alias for hasPermissionTo for Laravel Gate compatibility.
     */
    public function can($ability, $arguments = []): bool
    {
        $scope = null;
        if (! empty($arguments) && $arguments[0] instanceof Scope) {
            $scope = $arguments[0];
        }

        if ($this->hasPermissionTo($ability, $scope)) {
            return true;
        }

        if (method_exists(parent::class, 'can')) {
            return parent::can($ability, $arguments);
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Direct Permissions
    |--------------------------------------------------------------------------
    */

    /**
     * @param  string|Permission|array<int, string|Permission>  $permissions
     */
    public function givePermissionTo(string|Permission|array $permissions, ?Scope $scope = null): static
    {
        $scope = $scope ?? app('permixion')->resolveCurrentScope();
        $permissions = is_array($permissions) ? $permissions : [$permissions];

        foreach ($permissions as $permission) {
            $permissionName = $permission instanceof Permission ? $permission->name : $permission;
            app('permixion')->attachPermissionToUser($this, $permissionName, $scope);
        }

        return $this;
    }

    /**
     * @param  string|Permission|array<int, string|Permission>  $permissions
     */
    public function revokePermissionTo(string|Permission|array $permissions, ?Scope $scope = null): static
    {
        $scope = $scope ?? app('permixion')->resolveCurrentScope();
        $permissions = is_array($permissions) ? $permissions : [$permissions];

        foreach ($permissions as $permission) {
            $permissionName = $permission instanceof Permission ? $permission->name : $permission;
            app('permixion')->detachPermissionFromUser($this, $permissionName, $scope);
        }

        return $this;
    }

    public function getDirectPermissions(?Scope $scope = null): Collection
    {
        $scope = $scope ?? app('permixion')->resolveCurrentScope();
        $categoryId = app('permixion')->permissionsCategory()->id;
        $pivotTable = config('taxon.tables.taggables', 'taggables');

        $query = $this->tags()->where('parent_id', $categoryId);

        if ($scope !== null) {
            $query->where("{$pivotTable}.scope_type", $scope->getScopeType())
                ->where("{$pivotTable}.scope_id", $scope->getScopeId());
        } else {
            $query->whereNull("{$pivotTable}.scope_type")
                ->whereNull("{$pivotTable}.scope_id");
        }

        return $query->get()->map(fn ($tag) => new Permission($tag));
    }

    public function getAllPermissions(?Scope $scope = null): Collection
    {
        $scope = $scope ?? app('permixion')->resolveCurrentScope();

        $rolePermissions = collect();
        foreach ($this->getRoles($scope) as $role) {
            $rolePermissions = $rolePermissions->merge($role->getPermissions());
        }

        $directPermissions = $this->getDirectPermissions($scope);

        return $rolePermissions->merge($directPermissions)->unique('name');
    }
}
