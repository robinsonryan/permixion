<?php

namespace RobinsonRyan\Permixion;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Cache;
use RobinsonRyan\Taxon\Contracts\Scope;
use RobinsonRyan\Taxon\Models\Tag;
use RobinsonRyan\Permixion\Exceptions\PermissionAlreadyExists;
use RobinsonRyan\Permixion\Exceptions\PermissionDoesNotExist;
use RobinsonRyan\Permixion\Exceptions\RoleAlreadyExists;
use RobinsonRyan\Permixion\Exceptions\RoleDoesNotExist;
use RobinsonRyan\Permixion\Models\Permission;
use RobinsonRyan\Permixion\Models\Role;

class Permixion
{
    /*
    |--------------------------------------------------------------------------
    | Role Management
    |--------------------------------------------------------------------------
    */

    public function createRole(string $name, array $permissions = []): Role
    {
        $category = $this->rolesCategory();
        $slug = str()->slug($name);

        if ($category->children()->where('slug', $slug)->exists()) {
            if (config('permixion.strict')) {
                throw new RoleAlreadyExists($name);
            }

            return $this->findRole($name);
        }

        $tag = $category->addChild($name);

        $role = new Role($tag);

        if (! empty($permissions)) {
            $role->givePermissionTo($permissions);
        }

        $this->clearCache();

        return $role;
    }

    public function findRole(string $name): ?Role
    {
        $tag = Tag::inCategory($this->rolesCategorySlug())
            ->where('slug', str()->slug($name))
            ->first();

        return $tag ? new Role($tag) : null;
    }

    public function findRoleOrFail(string $name): Role
    {
        $role = $this->findRole($name);

        if (! $role) {
            throw new RoleDoesNotExist($name);
        }

        return $role;
    }

    public function roleExists(string $name): bool
    {
        return $this->findRole($name) !== null;
    }

    public function getAllRoles(): array
    {
        return $this->rolesCategory()
            ->children()
            ->get()
            ->map(fn (Tag $tag) => new Role($tag))
            ->all();
    }

    public function deleteRole(string $name): bool
    {
        $role = $this->findRole($name);

        if (! $role) {
            return false;
        }

        $role->tag()->delete();
        $this->clearCache();

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Permission Management
    |--------------------------------------------------------------------------
    */

    public function createPermission(string $name): Permission
    {
        $category = $this->permissionsCategory();
        $slug = str()->slug($name);

        if ($category->children()->where('slug', $slug)->exists()) {
            if (config('permixion.strict')) {
                throw new PermissionAlreadyExists($name);
            }

            return $this->findPermission($name);
        }

        $tag = $category->addChild($name);

        $this->clearCache();

        return new Permission($tag);
    }

    public function findPermission(string $name): ?Permission
    {
        $tag = Tag::inCategory($this->permissionsCategorySlug())
            ->where('slug', str()->slug($name))
            ->first();

        return $tag ? new Permission($tag) : null;
    }

    public function findPermissionOrFail(string $name): Permission
    {
        $permission = $this->findPermission($name);

        if (! $permission) {
            throw new PermissionDoesNotExist($name);
        }

        return $permission;
    }

    public function permissionExists(string $name): bool
    {
        return $this->findPermission($name) !== null;
    }

    public function getAllPermissions(): array
    {
        return $this->permissionsCategory()
            ->children()
            ->get()
            ->map(fn (Tag $tag) => new Permission($tag))
            ->all();
    }

    public function deletePermission(string $name): bool
    {
        $permission = $this->findPermission($name);

        if (! $permission) {
            return false;
        }

        $permission->tag()->delete();
        $this->clearCache();

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Permission Lookups (Cached)
    |--------------------------------------------------------------------------
    */

    public function getPermissionsForRole(string $roleName): array
    {
        if (! config('permixion.cache.enabled')) {
            return $this->fetchPermissionsForRole($roleName);
        }

        $cacheKey = $this->cacheKey("role_permissions.{$roleName}");
        $ttl = config('permixion.cache.ttl', 3600);

        return Cache::store($this->cacheStore())->remember(
            $cacheKey,
            $ttl,
            fn () => $this->fetchPermissionsForRole($roleName)
        );
    }

    protected function fetchPermissionsForRole(string $roleName): array
    {
        $role = $this->findRole($roleName);

        if (! $role) {
            return [];
        }

        return $role->tag()
            ->tags()
            ->whereHas('parent', fn ($q) => $q->where('slug', $this->permissionsCategorySlug()))
            ->pluck('slug')
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Wildcard Matching
    |--------------------------------------------------------------------------
    */

    public function permissionMatches(string $permission, string $pattern): bool
    {
        if (! config('permixion.wildcards.enabled')) {
            return $permission === $pattern;
        }

        if ($permission === $pattern) {
            return true;
        }

        $delimiter = config('permixion.wildcards.delimiter', '.');

        // Check if pattern is a wildcard
        if (str_ends_with($pattern, "{$delimiter}*")) {
            $prefix = substr($pattern, 0, -1); // Remove '*'

            return str_starts_with($permission, $prefix);
        }

        return false;
    }

    public function userHasPermission(
        Authorizable $user,
        string $permission,
        ?Scope $scope = null
    ): bool {
        if (! method_exists($user, 'getTagValueIn')) {
            return false;
        }

        // Get user's role in scope
        $roleSlug = $user->getTagValueIn($this->rolesCategorySlug(), scope: $scope);

        if (! $roleSlug) {
            // Check direct permissions
            return $user->hasTagIn($this->permissionsCategorySlug(), $permission, scope: $scope);
        }

        // Get permissions for role
        $rolePermissions = $this->getPermissionsForRole($roleSlug);

        // Check exact match
        if (in_array($permission, $rolePermissions, true)) {
            return true;
        }

        // Check wildcard matches
        foreach ($rolePermissions as $rolePermission) {
            if ($this->permissionMatches($permission, $rolePermission)) {
                return true;
            }
        }

        // Check direct permissions as fallback
        return $user->hasTagIn($this->permissionsCategorySlug(), $permission, scope: $scope);
    }

    /*
    |--------------------------------------------------------------------------
    | Scope Resolution
    |--------------------------------------------------------------------------
    */

    public function resolveCurrentScope(): ?Scope
    {
        $config = config('permixion.teams');

        if (! ($config['enabled'] ?? false)) {
            return null;
        }

        return match ($config['resolver'] ?? 'route') {
            'route' => $this->resolveFromRoute($config),
            'session' => $this->resolveFromSession($config),
            'callback' => $this->resolveFromCallback($config),
            default => null,
        };
    }

    protected function resolveFromRoute(array $config): ?Scope
    {
        $paramName = $config['route_parameter'] ?? 'team';
        $team = request()->route($paramName);

        if (! $team) {
            return null;
        }

        // If it's already a model implementing Scope
        if ($team instanceof Scope) {
            return $team;
        }

        // If using tag category for teams
        if ($tagCategory = $config['tag_category'] ?? null) {
            return Tag::inCategory($tagCategory)
                ->where('slug', $team)
                ->first();
        }

        // If using team model
        if ($modelClass = $config['model'] ?? null) {
            return $modelClass::find($team);
        }

        return null;
    }

    protected function resolveFromSession(array $config): ?Scope
    {
        $sessionKey = $config['session_key'] ?? 'current_team_id';
        $teamId = session($sessionKey);

        if (! $teamId) {
            return null;
        }

        // If using tag category for teams
        if ($tagCategory = $config['tag_category'] ?? null) {
            return Tag::inCategory($tagCategory)->find($teamId);
        }

        // If using team model
        if ($modelClass = $config['model'] ?? null) {
            return $modelClass::find($teamId);
        }

        return null;
    }

    protected function resolveFromCallback(array $config): ?Scope
    {
        $callback = $config['callback'] ?? null;

        if (! $callback || ! is_callable($callback)) {
            return null;
        }

        return $callback();
    }

    public function setCurrentScope(?Scope $scope): void
    {
        app()->instance('permixion.current-scope', $scope);
    }

    /*
    |--------------------------------------------------------------------------
    | Category Helpers
    |--------------------------------------------------------------------------
    */

    protected function rolesCategorySlug(): string
    {
        return config('permixion.categories.roles', 'roles');
    }

    protected function permissionsCategorySlug(): string
    {
        return config('permixion.categories.permissions', 'permissions');
    }

    public function rolesCategory(): Tag
    {
        return Tag::firstOrCreate(
            ['slug' => $this->rolesCategorySlug(), 'parent_id' => null],
            ['name' => 'Roles', 'assignable' => false]
        );
    }

    public function permissionsCategory(): Tag
    {
        return Tag::firstOrCreate(
            ['slug' => $this->permissionsCategorySlug(), 'parent_id' => null],
            ['name' => 'Permissions', 'assignable' => false]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Cache Management
    |--------------------------------------------------------------------------
    */

    protected function cacheKey(string $key): string
    {
        $prefix = config('permixion.cache.key_prefix', 'permixion');

        return "{$prefix}.{$key}";
    }

    protected function cacheStore(): ?string
    {
        return config('permixion.cache.store');
    }

    public function clearCache(): void
    {
        if (! config('permixion.cache.enabled')) {
            return;
        }

        $prefix = config('permixion.cache.key_prefix', 'permixion');
        Cache::store($this->cacheStore())->forget($prefix);

        // Clear all role permission caches
        foreach ($this->getAllRoles() as $role) {
            Cache::store($this->cacheStore())
                ->forget($this->cacheKey("role_permissions.{$role->slug}"));
        }
    }
}
