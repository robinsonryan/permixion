<?php

namespace RobinsonRyan\Permixion;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\Cache;
use RobinsonRyan\Permixion\Exceptions\PermissionAlreadyExists;
use RobinsonRyan\Permixion\Exceptions\PermissionDoesNotExist;
use RobinsonRyan\Permixion\Exceptions\RoleAlreadyExists;
use RobinsonRyan\Permixion\Exceptions\RoleDoesNotExist;
use RobinsonRyan\Permixion\Models\Permission;
use RobinsonRyan\Permixion\Models\Role;
use RobinsonRyan\Taxon\Contracts\Scope;
use RobinsonRyan\Taxon\Models\Tag;
use RobinsonRyan\Taxon\Models\Taggable;

class Permixion
{
    /*
    |--------------------------------------------------------------------------
    | Role Management
    |--------------------------------------------------------------------------
    |
    | Roles and permissions are identified by Tag.name (verbatim), not slug.
    | This preserves delimiter-bearing identifiers like 'posts.create' that
    | Str::slug would otherwise mangle.
    */

    /**
     * @param  array<int, string>  $permissions
     */
    public function createRole(string $name, array $permissions = []): Role
    {
        $category = $this->rolesCategory();

        if ($category->children()->where('name', $name)->exists()) {
            if (config('permixion.strict')) {
                throw new RoleAlreadyExists($name);
            }

            return $this->findRoleOrFail($name);
        }

        $tag = Tag::create([
            'name' => $name,
            'slug' => str()->slug($name),
            'parent_id' => $category->id,
            'assignable' => true,
        ]);

        $role = new Role($tag);

        if ($permissions !== []) {
            $role->givePermissionTo($permissions);
        }

        $this->clearCache();

        return $role;
    }

    public function findRole(string $name): ?Role
    {
        $tag = $this->rolesCategory()
            ->children()
            ->where('name', $name)
            ->first();

        return $tag ? new Role($tag) : null;
    }

    public function findRoleOrFail(string $name): Role
    {
        $role = $this->findRole($name);

        if (! $role instanceof Role) {
            throw new RoleDoesNotExist($name);
        }

        return $role;
    }

    public function roleExists(string $name): bool
    {
        return $this->findRole($name) instanceof Role;
    }

    /**
     * @return array<int, Role>
     */
    public function getAllRoles(): array
    {
        return $this->rolesCategory()
            ->children()
            ->get()
            ->map(fn (Tag $tag): Role => new Role($tag))
            ->all();
    }

    public function deleteRole(string $name): bool
    {
        $role = $this->findRole($name);

        if (! $role instanceof Role) {
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

        if ($category->children()->where('name', $name)->exists()) {
            if (config('permixion.strict')) {
                throw new PermissionAlreadyExists($name);
            }

            return $this->findPermissionOrFail($name);
        }

        $tag = Tag::create([
            'name' => $name,
            'slug' => str()->slug($name),
            'parent_id' => $category->id,
            'assignable' => true,
        ]);

        $this->clearCache();

        return new Permission($tag);
    }

    public function findPermission(string $name): ?Permission
    {
        $tag = $this->permissionsCategory()
            ->children()
            ->where('name', $name)
            ->first();

        return $tag ? new Permission($tag) : null;
    }

    public function findPermissionOrFail(string $name): Permission
    {
        $permission = $this->findPermission($name);

        if (! $permission instanceof Permission) {
            throw new PermissionDoesNotExist($name);
        }

        return $permission;
    }

    public function permissionExists(string $name): bool
    {
        return $this->findPermission($name) instanceof Permission;
    }

    /**
     * @return array<int, Permission>
     */
    public function getAllPermissions(): array
    {
        return $this->permissionsCategory()
            ->children()
            ->get()
            ->map(fn (Tag $tag): Permission => new Permission($tag))
            ->all();
    }

    public function deletePermission(string $name): bool
    {
        $permission = $this->findPermission($name);

        if (! $permission instanceof Permission) {
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

    /**
     * @return array<int, string>
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
            fn (): array => $this->fetchPermissionsForRole($roleName)
        );
    }

    /**
     * @return array<int, string>
     */
    protected function fetchPermissionsForRole(string $roleName): array
    {
        $role = $this->findRole($roleName);

        if (! $role instanceof Role) {
            return [];
        }

        $permissionsCategoryId = $this->permissionsCategory()->id;

        return $role->tag()
            ->tags()
            ->where('parent_id', $permissionsCategoryId)
            ->pluck('name')
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

        if (str_ends_with($pattern, "{$delimiter}*")) {
            $prefix = substr($pattern, 0, -1);

            return str_starts_with($permission, $prefix);
        }

        return false;
    }

    public function userHasPermission(
        Authorizable $user,
        string $permission,
        ?Scope $scope = null
    ): bool {
        if (! method_exists($user, 'tags')) {
            return false;
        }

        $roleNames = $this->getUserRoleNames($user, $scope);

        foreach ($roleNames as $roleName) {
            $rolePermissions = $this->getPermissionsForRole($roleName);

            if (in_array($permission, $rolePermissions, true)) {
                return true;
            }

            foreach ($rolePermissions as $rolePermission) {
                if ($this->permissionMatches($permission, $rolePermission)) {
                    return true;
                }
            }
        }

        return $this->userHasDirectPermission($user, $permission, $scope);
    }

    /*
    |--------------------------------------------------------------------------
    | User-Tag Operations (name-based, bypassing HasTags slug logic)
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<int, string>
     */
    public function getUserRoleNames(Authorizable $user, ?Scope $scope = null): array
    {
        if (! method_exists($user, 'tags')) {
            return [];
        }

        $categoryId = $this->rolesCategory()->id;
        $pivotTable = config('taxon.tables.taggables', 'taggables');

        $query = $user->tags()->where('parent_id', $categoryId);
        $this->applyScopeToPivot($query, $pivotTable, $scope);

        return $query->pluck('name')->all();
    }

    public function userHasRole(Authorizable $user, string $roleName, ?Scope $scope = null): bool
    {
        if (! method_exists($user, 'tags')) {
            return false;
        }

        $categoryId = $this->rolesCategory()->id;
        $pivotTable = config('taxon.tables.taggables', 'taggables');

        $query = $user->tags()
            ->where('parent_id', $categoryId)
            ->where('name', $roleName);

        $this->applyScopeToPivot($query, $pivotTable, $scope);

        return $query->exists();
    }

    public function attachRoleToUser(Authorizable $user, string $roleName, ?Scope $scope = null): void
    {
        if (! method_exists($user, 'tags')) {
            return;
        }

        if (config('permixion.strict')) {
            $this->findRoleOrFail($roleName);
            $tag = $this->findRoleOrFail($roleName)->tag();
        } else {
            $tag = $this->findRole($roleName)?->tag()
                ?? $this->createRole($roleName)->tag();
        }

        if ($this->userTagPivotExists($user, $tag->id, $scope)) {
            return;
        }

        $user->tags()->attach($tag->id, $this->scopePivotData($scope));
    }

    public function detachRoleFromUser(Authorizable $user, string $roleName, ?Scope $scope = null): void
    {
        if (! method_exists($user, 'tags')) {
            return;
        }

        $tag = $this->findRole($roleName)?->tag();

        if (! $tag instanceof Tag) {
            return;
        }

        $this->deleteUserTagPivot($user, $tag->id, $scope);
    }

    public function detachAllUserRoles(Authorizable $user, ?Scope $scope = null): void
    {
        if (! method_exists($user, 'tags')) {
            return;
        }

        $categoryId = $this->rolesCategory()->id;
        $pivotTable = config('taxon.tables.taggables', 'taggables');

        $query = $user->tags()->where('parent_id', $categoryId);
        $this->applyScopeToPivot($query, $pivotTable, $scope);

        foreach ($query->get() as $tag) {
            $this->deleteUserTagPivot($user, $tag->id, $scope);
        }
    }

    public function userHasDirectPermission(Authorizable $user, string $permissionName, ?Scope $scope = null): bool
    {
        if (! method_exists($user, 'tags')) {
            return false;
        }

        $categoryId = $this->permissionsCategory()->id;
        $pivotTable = config('taxon.tables.taggables', 'taggables');

        $query = $user->tags()
            ->where('parent_id', $categoryId)
            ->where('name', $permissionName);

        $this->applyScopeToPivot($query, $pivotTable, $scope);

        return $query->exists();
    }

    /**
     * @return array<int, string>
     */
    public function getUserDirectPermissionNames(Authorizable $user, ?Scope $scope = null): array
    {
        if (! method_exists($user, 'tags')) {
            return [];
        }

        $categoryId = $this->permissionsCategory()->id;
        $pivotTable = config('taxon.tables.taggables', 'taggables');

        $query = $user->tags()->where('parent_id', $categoryId);
        $this->applyScopeToPivot($query, $pivotTable, $scope);

        return $query->pluck('name')->all();
    }

    public function attachPermissionToUser(Authorizable $user, string $permissionName, ?Scope $scope = null): void
    {
        if (! method_exists($user, 'tags')) {
            return;
        }

        if (config('permixion.strict')) {
            $tag = $this->findPermissionOrFail($permissionName)->tag();
        } else {
            $tag = $this->findPermission($permissionName)?->tag()
                ?? $this->createPermission($permissionName)->tag();
        }

        if ($this->userTagPivotExists($user, $tag->id, $scope)) {
            return;
        }

        $user->tags()->attach($tag->id, $this->scopePivotData($scope));
    }

    public function detachPermissionFromUser(Authorizable $user, string $permissionName, ?Scope $scope = null): void
    {
        if (! method_exists($user, 'tags')) {
            return;
        }

        $tag = $this->findPermission($permissionName)?->tag();

        if (! $tag instanceof Tag) {
            return;
        }

        $this->deleteUserTagPivot($user, $tag->id, $scope);
    }

    /*
    |--------------------------------------------------------------------------
    | Pivot Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, string|int|null>
     */
    protected function scopePivotData(?Scope $scope): array
    {
        return $scope instanceof Scope ? [
            'scope_type' => $scope->getScopeType(),
            'scope_id' => $scope->getScopeId(),
        ] : [];
    }

    protected function applyScopeToPivot(mixed $query, string $pivotTable, ?Scope $scope): void
    {
        if ($scope instanceof Scope) {
            $query->where("{$pivotTable}.scope_type", $scope->getScopeType())
                ->where("{$pivotTable}.scope_id", $scope->getScopeId());

            return;
        }

        $query->whereNull("{$pivotTable}.scope_type")
            ->whereNull("{$pivotTable}.scope_id");
    }

    protected function userTagPivotExists(Authorizable $user, int|string $tagId, ?Scope $scope): bool
    {
        $query = Taggable::query()
            ->where('tag_id', $tagId)
            ->where('taggable_type', $user->getMorphClass())
            ->where('taggable_id', $user->getKey());

        if ($scope instanceof Scope) {
            $query->where('scope_type', $scope->getScopeType())
                ->where('scope_id', $scope->getScopeId());
        } else {
            $query->whereNull('scope_type')
                ->whereNull('scope_id');
        }

        return $query->exists();
    }

    protected function deleteUserTagPivot(Authorizable $user, int|string $tagId, ?Scope $scope): void
    {
        $query = Taggable::query()
            ->where('tag_id', $tagId)
            ->where('taggable_type', $user->getMorphClass())
            ->where('taggable_id', $user->getKey());

        if ($scope instanceof Scope) {
            $query->where('scope_type', $scope->getScopeType())
                ->where('scope_id', $scope->getScopeId());
        } else {
            $query->whereNull('scope_type')
                ->whereNull('scope_id');
        }

        $query->delete();
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

    /**
     * @param  array<string, mixed>  $config
     */
    protected function resolveFromRoute(array $config): ?Scope
    {
        $paramName = $config['route_parameter'] ?? 'team';
        $team = request()->route($paramName);

        if (! $team) {
            return null;
        }

        if ($team instanceof Scope) {
            return $team;
        }

        if ($tagCategory = $config['tag_category'] ?? null) {
            $tag = Tag::inCategory($tagCategory)
                ->where('slug', $team)
                ->first();

            return $tag instanceof Scope ? $tag : null;
        }

        if ($modelClass = $config['model'] ?? null) {
            $model = $modelClass::find($team);

            return $model instanceof Scope ? $model : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function resolveFromSession(array $config): ?Scope
    {
        $sessionKey = $config['session_key'] ?? 'current_team_id';
        $teamId = session($sessionKey);

        if (! $teamId) {
            return null;
        }

        if ($tagCategory = $config['tag_category'] ?? null) {
            $tag = Tag::inCategory($tagCategory)->find($teamId);

            return $tag instanceof Scope ? $tag : null;
        }

        if ($modelClass = $config['model'] ?? null) {
            $model = $modelClass::find($teamId);

            return $model instanceof Scope ? $model : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $config
     */
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

        foreach ($this->getAllRoles() as $role) {
            Cache::store($this->cacheStore())
                ->forget($this->cacheKey("role_permissions.{$role->name}"));
        }
    }
}
