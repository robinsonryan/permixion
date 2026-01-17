<?php

namespace RobinsonRyan\Permixion\Traits;

use Illuminate\Support\Collection;
use RobinsonRyan\Taxon\Contracts\Scope;
use RobinsonRyan\Permixion\Models\Permission;
use RobinsonRyan\Permixion\Models\Role;

trait HasRoles
{
    /*
    |--------------------------------------------------------------------------
    | Role Assignment
    |--------------------------------------------------------------------------
    */

    /**
     * Assign a role to the user.
     *
     * @param  string|Role|array  $roles
     * @param  Scope|null  $scope  Team/context scope
     */
    public function assignRole(string|Role|array $roles, ?Scope $scope = null): static
    {
        $scope = $scope ?? app('permixion')->resolveCurrentScope();
        $roles = is_array($roles) ? $roles : [$roles];
        $categorySlug = config('permixion.categories.roles', 'roles');

        foreach ($roles as $role) {
            $roleName = $role instanceof Role ? $role->slug : $role;

            // Validate role exists
            if (config('permixion.strict')) {
                app('permixion')->findRoleOrFail($roleName);
            }

            $this->addTag($categorySlug, $roleName, scope: $scope);
        }

        return $this;
    }

    /**
     * Remove a role from the user.
     */
    public function removeRole(string|Role $role, ?Scope $scope = null): static
    {
        $scope = $scope ?? app('permixion')->resolveCurrentScope();
        $roleName = $role instanceof Role ? $role->slug : $role;
        $categorySlug = config('permixion.categories.roles', 'roles');

        $this->removeTag($categorySlug, $roleName, scope: $scope);

        return $this;
    }

    /**
     * Sync roles - remove all current roles and assign new ones.
     */
    public function syncRoles(array $roles, ?Scope $scope = null): static
    {
        $scope = $scope ?? app('permixion')->resolveCurrentScope();
        $categorySlug = config('permixion.categories.roles', 'roles');

        $this->removeTagsIn($categorySlug, scope: $scope);

        foreach ($roles as $role) {
            $this->assignRole($role, $scope);
        }

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Role Checks
    |--------------------------------------------------------------------------
    */

    /**
     * Check if user has a role.
     */
    public function hasRole(string|Role $role, ?Scope $scope = null): bool
    {
        $scope = $scope ?? app('permixion')->resolveCurrentScope();
        $roleName = $role instanceof Role ? $role->slug : str()->slug($role);
        $categorySlug = config('permixion.categories.roles', 'roles');

        return $this->hasTagIn($categorySlug, $roleName, scope: $scope);
    }

    /**
     * Check if user has any of the given roles.
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
     * Check if user has all of the given roles.
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
     * Check if user has exactly the given roles (no more, no less).
     */
    public function hasExactRoles(array $roles, ?Scope $scope = null): bool
    {
        $currentRoles = $this->getRoleNames($scope);

        if (count($currentRoles) !== count($roles)) {
            return false;
        }

        $roles = array_map(fn ($r) => str()->slug($r), $roles);

        return empty(array_diff($currentRoles, $roles)) && empty(array_diff($roles, $currentRoles));
    }

    /*
    |--------------------------------------------------------------------------
    | Role Retrieval
    |--------------------------------------------------------------------------
    */

    /**
     * Get all roles for the user.
     */
    public function getRoles(?Scope $scope = null): Collection
    {
        $scope = $scope ?? app('permixion')->resolveCurrentScope();
        $categorySlug = config('permixion.categories.roles', 'roles');

        return $this->tagsIn($categorySlug, scope: $scope)
            ->map(fn ($tag) => new Role($tag));
    }

    /**
     * Get role names as array.
     */
    public function getRoleNames(?Scope $scope = null): array
    {
        return $this->getRoles($scope)->pluck('slug')->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Permission Checks
    |--------------------------------------------------------------------------
    */

    /**
     * Check if user has a permission (via roles or direct assignment).
     */
    public function hasPermissionTo(string|Permission $permission, ?Scope $scope = null): bool
    {
        $scope = $scope ?? app('permixion')->resolveCurrentScope();
        $permissionName = $permission instanceof Permission ? $permission->slug : $permission;

        // Check super admin
        if (config('permixion.super_admin.enabled')) {
            $superAdminRole = config('permixion.super_admin.role');
            if ($this->hasRole($superAdminRole, $scope)) {
                return true;
            }
        }

        return app('permixion')->userHasPermission($this, $permissionName, $scope);
    }

    /**
     * Check if user has any of the given permissions.
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
     * Check if user has all of the given permissions.
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
        // If arguments include a scope, use it
        $scope = null;
        if (! empty($arguments) && $arguments[0] instanceof Scope) {
            $scope = $arguments[0];
        }

        if ($this->hasPermissionTo($ability, $scope)) {
            return true;
        }

        // Fall back to parent can() if it exists
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
     * Give a permission directly to the user (not via role).
     */
    public function givePermissionTo(string|Permission|array $permissions, ?Scope $scope = null): static
    {
        $scope = $scope ?? app('permixion')->resolveCurrentScope();
        $permissions = is_array($permissions) ? $permissions : [$permissions];
        $categorySlug = config('permixion.categories.permissions', 'permissions');

        foreach ($permissions as $permission) {
            $permissionName = $permission instanceof Permission ? $permission->slug : $permission;

            // Validate permission exists
            if (config('permixion.strict')) {
                app('permixion')->findPermissionOrFail($permissionName);
            }

            $this->addTag($categorySlug, $permissionName, scope: $scope);
        }

        return $this;
    }

    /**
     * Revoke a direct permission from the user.
     */
    public function revokePermissionTo(string|Permission|array $permissions, ?Scope $scope = null): static
    {
        $scope = $scope ?? app('permixion')->resolveCurrentScope();
        $permissions = is_array($permissions) ? $permissions : [$permissions];
        $categorySlug = config('permixion.categories.permissions', 'permissions');

        foreach ($permissions as $permission) {
            $permissionName = $permission instanceof Permission ? $permission->slug : $permission;
            $this->removeTag($categorySlug, $permissionName, scope: $scope);
        }

        return $this;
    }

    /**
     * Get all direct permissions for the user.
     */
    public function getDirectPermissions(?Scope $scope = null): Collection
    {
        $scope = $scope ?? app('permixion')->resolveCurrentScope();
        $categorySlug = config('permixion.categories.permissions', 'permissions');

        return $this->tagsIn($categorySlug, scope: $scope)
            ->map(fn ($tag) => new Permission($tag));
    }

    /**
     * Get all permissions (from roles + direct).
     */
    public function getAllPermissions(?Scope $scope = null): Collection
    {
        $scope = $scope ?? app('permixion')->resolveCurrentScope();

        // Get permissions from roles
        $rolePermissions = collect();
        foreach ($this->getRoles($scope) as $role) {
            $rolePermissions = $rolePermissions->merge($role->getPermissions());
        }

        // Get direct permissions
        $directPermissions = $this->getDirectPermissions($scope);

        return $rolePermissions->merge($directPermissions)->unique('slug');
    }
}
