<?php

namespace RobinsonRyan\Permixion\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RobinsonRyan\Permixion\Models\Permission;
use RobinsonRyan\Permixion\Models\Role;
use RobinsonRyan\Permixion\Permixion;
use RobinsonRyan\Taxon\Contracts\Scope;

/**
 * HasRoles uses Tag.name (verbatim) as the identity for roles and
 * permissions. It deliberately bypasses Taxon's HasTags helpers
 * (addTag/hasTagIn/etc.) because those slug their input via Str::slug,
 * which would mangle delimiter-bearing identifiers like 'posts.create'.
 */
trait HasRoles
{
    /**
     * Request-scoped ceilings added by withCeiling(). Never persisted.
     *
     * @var array<int, string>
     */
    protected array $permixionCeilings = [];

    /*
    |--------------------------------------------------------------------------
    | Role Assignment
    |--------------------------------------------------------------------------
    */

    /**
     * Assign one or more roles, optionally acting THROUGH holders and/or roles.
     *
     * Each through caps the assignment: a holder to what that holder may do in
     * the same scope (recursively), a role to that role's permissions. Throughs
     * are only ever added — re-assigning with fewer never lifts a cap; remove the
     * role and assign it again to change them.
     *
     * @param  string|Role|array<int, string|Role>  $roles
     * @param  array<int, Model|Role|string>  $through
     */
    public function assignRole(string|Role|array $roles, ?Scope $scope = null, array $through = []): static
    {
        $scope ??= app('permixion')->resolveCurrentScope();
        $roles = is_array($roles) ? $roles : [$roles];

        foreach ($roles as $role) {
            $roleName = $role instanceof Role ? $role->name : $role;
            app('permixion')->attachRoleToUser($this, $roleName, $scope, $through);
        }

        return $this;
    }

    public function removeRole(string|Role $role, ?Scope $scope = null): static
    {
        $scope ??= app('permixion')->resolveCurrentScope();
        $roleName = $role instanceof Role ? $role->name : $role;

        app('permixion')->detachRoleFromUser($this, $roleName, $scope);

        return $this;
    }

    /**
     * Make the roles in this exact scope exactly $roles — never widening.
     *
     * A role already held is left in place, throughs and all. A capped role it
     * removes hands its throughs on to the roles it newly assigns, so changing a
     * capped holder's role keeps the cap. $through caps every role in $roles,
     * as assignRole()'s does.
     *
     * @param  array<int, string|Role>  $roles
     * @param  array<int, Model|Role|string>  $through
     */
    public function syncRoles(array $roles, ?Scope $scope = null, array $through = []): static
    {
        $scope ??= app('permixion')->resolveCurrentScope();

        $wanted = array_map(fn (string|Role $role): string => $role instanceof Role ? $role->name : $role, $roles);

        app('permixion')->syncUserRoles($this, $wanted, $scope, $through);

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Role Checks
    |--------------------------------------------------------------------------
    */

    public function hasRole(string|Role $role, ?Scope $scope = null): bool
    {
        $scope ??= app('permixion')->resolveCurrentScope();
        $roleName = $role instanceof Role ? $role->name : $role;

        return app('permixion')->userHasRole($this, $roleName, $scope);
    }

    /**
     * Whether the user holds the role under ANY scope (including unscoped),
     * ignoring the current scope context entirely.
     */
    public function hasRoleAnywhere(string|Role $role): bool
    {
        $roleName = $role instanceof Role ? $role->name : $role;

        return app('permixion')->userHasRoleInAnyScope($this, $roleName);
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
            fn (\RobinsonRyan\Permixion\Models\Role|string $r): string => $r instanceof Role ? $r->name : $r,
            $roles,
        );

        if (count($currentRoles) !== count($expected)) {
            return false;
        }

        return array_diff($currentRoles, $expected) === []
            && array_diff($expected, $currentRoles) === [];
    }

    /*
    |--------------------------------------------------------------------------
    | Role Retrieval
    |--------------------------------------------------------------------------
    */

    /**
     * The roles in force in $scope — assigned, and surviving their throughs and
     * ceilings whole. See getAssignedRoleNames() for what is merely stored.
     *
     * @return Collection<int, Role>
     */
    public function getRoles(?Scope $scope = null): Collection
    {
        $scope ??= app('permixion')->resolveCurrentScope();
        $inForce = $this->getRoleNames($scope);

        return app('permixion')->rolesCategory()
            ->children()
            ->whereIn('name', $inForce)
            ->get()
            ->map(fn ($tag): Role => new Role($tag))
            ->values();
    }

    /**
     * The names of the roles in force in $scope.
     *
     * @return array<int, string>
     */
    public function getRoleNames(?Scope $scope = null): array
    {
        return app('permixion')->getUserRoleNames($this, $scope);
    }

    /**
     * The names of the roles stored against this holder in $scope, whether in
     * force or not — for showing or reconciling assignments. Never authorize on
     * it: a capped or inactive holder's roles are listed here too.
     *
     * @return array<int, string>
     */
    public function getAssignedRoleNames(?Scope $scope = null): array
    {
        $scope ??= app('permixion')->resolveCurrentScope();

        return app('permixion')->getUserAssignedRoleNames($this, $scope);
    }

    /*
    |--------------------------------------------------------------------------
    | Permission Checks
    |--------------------------------------------------------------------------
    */

    /**
     * Whether this holder may do $permission in $scope — decided by its
     * effective permissions, so throughs, ceilings, the inactive state and the
     * super-admin role all apply.
     */
    public function hasPermissionTo(string|Permission $permission, ?Scope $scope = null): bool
    {
        $scope ??= app('permixion')->resolveCurrentScope();
        $permissionName = $permission instanceof Permission ? $permission->name : $permission;

        return app('permixion')->effectivePermissionsFor($this, $scope)->grants($permissionName);
    }

    /**
     * What this holder may do in $scope: the union over its assignments there of
     * (role ∩ every through), plus direct permissions, cut by any ceiling.
     *
     * Permission names and wildcard patterns as stored. An uncapped super admin
     * may do anything; that is listed as every permission that exists.
     *
     * @return Collection<int, string>
     */
    public function effectivePermissions(?Scope $scope = null): Collection
    {
        $permixion = app(Permixion::class);
        $scope ??= $permixion->resolveCurrentScope();
        $effective = $permixion->effectivePermissionsFor($this, $scope);

        if ($effective->isUnlimited()) {
            return (new Collection($permixion->getAllPermissions()))
                ->map(fn (Permission $permission): string => $permission->name)
                ->values();
        }

        return new Collection($effective->patterns());
    }

    /**
     * A copy of this holder capped, for this request only, at $role's
     * permissions — e.g. a person acting through an app's key asks for less than
     * the key holds. Immutable: the original is untouched. Ceilings stack (they
     * intersect), can never widen, and are not persisted or serialized — a
     * model re-fetched from the database (a queued job) has none.
     */
    public function withCeiling(string|Role $role): static
    {
        $copy = clone $this;
        $copy->permixionCeilings[] = $role instanceof Role ? $role->name : $role;

        return $copy;
    }

    /**
     * The ceilings withCeiling() has added to this instance.
     *
     * @return array<int, string>
     */
    public function permixionCeilings(): array
    {
        return $this->permixionCeilings;
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
     *
     * Ability forms Permixion cannot resolve itself (iterables, enums) are handed
     * straight to the parent `can()` — Permixion never widens what those grant.
     *
     * A permission is a NAME. It can express "may update posts at all"; it cannot
     * express "may update *this* post". So when the caller supplies a subject for a
     * policy to inspect, this method declines to answer and delegates — the policy
     * is the only thing that can see the instance. Without that rule a coarse
     * permission silently outranks every per-instance clause a policy contains.
     *
     * Note this is deliberately NOT governed by `permixion.register_gate`, which
     * covers only the Gate::before hook. The two short-circuits are independent.
     *
     * @param  iterable<array-key, mixed>|string|\UnitEnum|Permission  $ability
     * @param  mixed  $arguments
     */
    public function can($ability, $arguments = []): bool
    {
        if (app('permixion')->argumentsCarryPolicySubject($arguments)) {
            return $this->parentCan($ability, $arguments);
        }

        // A bare Scope scopes exactly as [$scope] does.
        $scope = app('permixion')->scopeFromArguments($arguments);

        if (($ability instanceof Permission || is_string($ability)) && $this->hasPermissionTo($ability, $scope)) {
            return true;
        }

        return $this->parentCan($ability, $arguments);
    }

    /**
     * @param  iterable<array-key, mixed>|string|\UnitEnum|Permission  $ability
     * @param  mixed  $arguments
     */
    protected function parentCan($ability, $arguments = []): bool
    {
        // Generic trait: `parent` is whatever class the consumer mixes this into, so
        // this guard is only provably true when analysed against one concrete host
        // (the test fixture). Removing it fatals for hosts with no parent `can()`.
        // @phpstan-ignore function.alreadyNarrowedType (analyser sees a single host class)
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
        $scope ??= app('permixion')->resolveCurrentScope();
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
        $scope ??= app('permixion')->resolveCurrentScope();
        $permissions = is_array($permissions) ? $permissions : [$permissions];

        foreach ($permissions as $permission) {
            $permissionName = $permission instanceof Permission ? $permission->name : $permission;
            app('permixion')->detachPermissionFromUser($this, $permissionName, $scope);
        }

        return $this;
    }

    /**
     * @return Collection<int, Permission>
     */
    public function getDirectPermissions(?Scope $scope = null): Collection
    {
        $scope ??= app('permixion')->resolveCurrentScope();
        $categoryId = app('permixion')->permissionsCategory()->id;
        $pivotTable = config('taxon.tables.taggables', 'taggables');

        $query = $this->tags()->where('parent_id', $categoryId);
        app('permixion')->applyReadScopeToPivot($query, $pivotTable, $scope);

        return $query->get()->map(fn ($tag): Permission => new Permission($tag));
    }

    /**
     * Every permission this holder may use in $scope — its effective
     * permissions, as Permission objects. An uncapped super admin lists every
     * permission that exists.
     *
     * @return Collection<array-key, Permission>
     */
    public function getAllPermissions(?Scope $scope = null): Collection
    {
        $names = $this->effectivePermissions($scope)->all();

        return app('permixion')->permissionsCategory()
            ->children()
            ->whereIn('name', $names)
            ->get()
            ->map(fn ($tag): Permission => new Permission($tag))
            ->values();
    }
}
