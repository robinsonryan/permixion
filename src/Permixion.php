<?php

namespace RobinsonRyan\Permixion;

use ArrayObject;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RobinsonRyan\Permixion\Contracts\CanBeInactive;
use RobinsonRyan\Permixion\Exceptions\AssignmentRouteConflict;
use RobinsonRyan\Permixion\Exceptions\PermissionAlreadyExists;
use RobinsonRyan\Permixion\Exceptions\PermissionDoesNotExist;
use RobinsonRyan\Permixion\Exceptions\PermissionSlugCollision;
use RobinsonRyan\Permixion\Exceptions\RoleAlreadyExists;
use RobinsonRyan\Permixion\Exceptions\RoleDoesNotExist;
use RobinsonRyan\Permixion\Exceptions\RoleSlugCollision;
use RobinsonRyan\Permixion\Models\Permission;
use RobinsonRyan\Permixion\Models\Role;
use RobinsonRyan\Taxon\Contracts\Scope;
use RobinsonRyan\Taxon\Models\Tag;
use RobinsonRyan\Taxon\Models\Taggable;
use stdClass;

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
    |
    | The slug column is written verbatim from the name for the same reason.
    | Taxon 0.4.0's harden_tags_unique_index_against_nulls migration makes
    | `tags_unique_slug_parent_tenant` enforce uniqueness over COALESCE'd nulls,
    | so two sibling tags may no longer share a slug. Str::slug is lossy —
    | 'account.view' and 'accountview' both reduce to 'accountview' — so a
    | slugged copy would make two legitimately distinct permissions collide in
    | the database. Verbatim is the only mapping that is injective on the name,
    | which leaves the index enforcing exactly the identity documented above.
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

        try {
            $tag = $this->insertTag($name, $category);
        } catch (UniqueConstraintViolationException $e) {
            throw new RoleSlugCollision($name, $this->tagNameOccupyingSlug($category, $name), $e);
        }

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

        try {
            $tag = $this->insertTag($name, $category);
        } catch (UniqueConstraintViolationException $e) {
            throw new PermissionSlugCollision($name, $this->tagNameOccupyingSlug($category, $name), $e);
        }

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

    /**
     * Whether $arguments carries something a policy is expected to inspect.
     *
     * A permission is a NAME: it can express "may update posts at all", never
     * "may update *this* post". So the presence of a subject marks a question
     * only a policy can answer. A Scope is Permixion's own concern and does not
     * count; anything else — a model, a class-string, a DTO — does.
     *
     * Shared by both short-circuits (the Gate::before hook and HasRoles::can())
     * so the two can never drift apart on what counts as a policy question.
     */
    public function argumentsCarryPolicySubject(mixed $arguments): bool
    {
        if ($arguments === null || $arguments === []) {
            return false;
        }

        if ($arguments instanceof Scope) {
            return false;
        }

        if (is_array($arguments)) {
            foreach ($arguments as $argument) {
                if (! $argument instanceof Scope) {
                    return true;
                }
            }

            return false;
        }

        // An ArrayAccess container may legitimately carry a Scope at index 0. An
        // Eloquent model is also ArrayAccess but yields null there, so it reads as
        // a policy subject — which is precisely what it is.
        if ($arguments instanceof \ArrayAccess) {
            return ! (($arguments[0] ?? null) instanceof Scope);
        }

        return true;
    }

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

    /**
     * Whether the holder may do $permission in $scope: its effective
     * permissions, so every through, ceiling, inactive state and the super-admin
     * role are honoured. The scope is taken as given (null = unscoped rows).
     */
    public function userHasPermission(
        Authorizable $user,
        string $permission,
        ?Scope $scope = null
    ): bool {
        if (! $user instanceof Model) {
            return false;
        }

        return $this->effectivePermissionsFor($user, $scope)->grants($permission);
    }

    /**
     * The Scope a gate-style $arguments value names, if any: a bare Scope, or a
     * Scope first in an array / ArrayAccess. `can('x', $team)` and
     * `can('x', [$team])` must mean the same thing, as they do to Laravel.
     */
    public function scopeFromArguments(mixed $arguments): ?Scope
    {
        if ($arguments instanceof Scope) {
            return $arguments;
        }

        // Only subscript what is actually subscriptable: a plain object argument
        // (`$user->can('update', $dto)`) must not fatal here.
        if (is_array($arguments) || $arguments instanceof \ArrayAccess) {
            $first = $arguments[0] ?? null;

            return $first instanceof Scope ? $first : null;
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Effective Permissions (acting for)
    |--------------------------------------------------------------------------
    |
    | What a holder may do in a scope: the union, over its role assignments
    | there, of (the role's permissions ∩ every through's effective
    | permissions), plus its direct permissions — then cut by any request-scoped
    | ceiling the instance carries (withCeiling()).
    |
    | Every failure mode narrows: an inactive holder, a cycle, a chain deeper
    | than permixion.through.max_depth, a through holder or ceiling role that
    | no longer exists — each contributes nothing.
    */

    public function effectivePermissionsFor(Model $holder, ?Scope $scope = null): EffectivePermissions
    {
        // Read live, never from the memo: deactivating the holder being checked
        // must take effect at once.
        if (! $this->isActiveHolder($holder)) {
            return EffectivePermissions::none();
        }

        $memo = $this->effectiveMemo();
        $key = $this->memoKey($holder, $scope);
        $ttl = config('permixion.memo.ttl');
        $ttl = $ttl === null ? null : (int) $ttl;

        if ($key === null || $ttl === 0) {
            $effective = $this->computeEffective($holder, $scope, [], 0);
        } else {
            $now = now()->getTimestamp();
            $entry = $memo[$key] ?? null;

            if ($entry === null || ($entry['expires'] !== null && $entry['expires'] <= $now)) {
                $entry = [
                    'result' => $this->computeEffective($holder, $scope, [], 0),
                    'expires' => $ttl === null ? null : $now + $ttl,
                ];
                $memo[$key] = $entry;
            }

            $effective = $entry['result'];
        }

        return $effective->intersect($this->ceilingSet($holder));
    }

    /**
     * The roles IN FORCE for the holder in $scope — not merely assigned.
     *
     * A role is in force when the holder is active and the role survives its
     * own assignment's throughs, and the holder's withCeiling() ceilings, whole:
     * every permission of the role is still granted. A role with no permissions
     * is in force only when nothing caps it, so a cycle, a deleted or inactive
     * through or a too-deep chain can never leave it standing. The super-admin
     * role is in force only when the holder's effective permissions are
     * unlimited. Every role question (hasRole and its family, the role
     * middleware, the @role directives) asks this.
     *
     * @return array<int, string>
     */
    public function roleNamesInForce(Model $holder, ?Scope $scope = null): array
    {
        if (! $this->isActiveHolder($holder) || $holder->getKey() === null) {
            return [];
        }

        $ceiling = $this->ceilingSet($holder);
        $path = [$this->holderIdentity($holder)];
        $names = [];

        foreach ($this->roleAssignments($holder, $scope) as $assignment) {
            $name = $assignment->name;

            if (in_array($name, $names, true)) {
                continue;
            }

            if ($this->isSuperAdminRole($name)) {
                if ($this->effectivePermissionsFor($holder, $scope)->isUnlimited()) {
                    $names[] = $name;
                }

                continue;
            }

            $cap = $ceiling;

            foreach ($assignment->throughs as $through) {
                $cap = $cap->intersect($this->throughPermissionSet($through, $scope, $path, 0));
            }

            $role = $this->rolePermissionSet($name);

            if ($role->isEmpty() ? $cap->isUnlimited() : $cap->includes($role)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    public function roleInForce(Model $holder, string $roleName, ?Scope $scope = null): bool
    {
        return in_array($roleName, $this->roleNamesInForce($holder, $scope), true);
    }

    /**
     * Every withCeiling() ceiling on the holder, intersected (unlimited if none).
     */
    protected function ceilingSet(Model $holder): EffectivePermissions
    {
        $set = EffectivePermissions::unlimited();
        $ceilings = method_exists($holder, 'permixionCeilings') ? $holder->permixionCeilings() : [];

        foreach ($ceilings as $ceiling) {
            $set = $set->intersect($this->rolePermissionSet($ceiling));
        }

        return $set;
    }

    /**
     * Forget every effective-permission result memoised in this request.
     *
     * Permixion's own writes call this. Call it yourself only after changing,
     * mid-request, something permixion cannot see — a through holder's active
     * state, or a taggable row written behind permixion's back.
     */
    public function flushEffectivePermissions(): void
    {
        $this->effectiveMemo()->exchangeArray([]);
    }

    /**
     * @param  array<int, string>  $path  holder identities already being evaluated
     */
    protected function computeEffective(Model $holder, ?Scope $scope, array $path, int $depth): EffectivePermissions
    {
        if (! $this->isActiveHolder($holder) || $holder->getKey() === null) {
            return EffectivePermissions::none();
        }

        $identity = $this->holderIdentity($holder);

        // A holder already on the path contributes nothing: a cycle caps to nothing.
        if (in_array($identity, $path, true)) {
            return EffectivePermissions::none();
        }

        $path[] = $identity;

        $effective = EffectivePermissions::none();

        foreach ($this->roleAssignments($holder, $scope) as $assignment) {
            $granted = $this->rolePermissionSet($assignment->name);

            foreach ($assignment->throughs as $through) {
                if ($granted->isEmpty()) {
                    break;
                }

                $granted = $granted->intersect($this->throughPermissionSet($through, $scope, $path, $depth));
            }

            $effective = $effective->union($granted);
        }

        $direct = array_map(
            fn (stdClass $row): string => $row->name,
            $this->holderTagRows($holder, $this->permissionsCategory(), $scope)
        );

        return $effective->union(EffectivePermissions::of($direct));
    }

    /**
     * @param  array<int, string>  $path
     */
    protected function throughPermissionSet(stdClass $through, ?Scope $scope, array $path, int $depth): EffectivePermissions
    {
        if ($through->holder_type !== null && $through->holder_id !== null) {
            if ($depth + 1 > (int) config('permixion.through.max_depth', 5)) {
                return EffectivePermissions::none();
            }

            $holder = $this->findHolder($through->holder_type, $through->holder_id);

            return $holder instanceof Model
                ? $this->computeEffective($holder, $scope, $path, $depth + 1)
                : EffectivePermissions::none();
        }

        // A ceiling role. Its name is null when the role was deleted: the row then
        // names nothing, and an assignment capped by nothing grants nothing.
        return $through->role_name !== null
            ? $this->rolePermissionSet($through->role_name)
            : EffectivePermissions::none();
    }

    protected function rolePermissionSet(string $roleName): EffectivePermissions
    {
        if ($this->isSuperAdminRole($roleName)) {
            return EffectivePermissions::unlimited();
        }

        return EffectivePermissions::of($this->getPermissionsForRole($roleName));
    }

    protected function isSuperAdminRole(string $roleName): bool
    {
        return config('permixion.super_admin.enabled') && $roleName === config('permixion.super_admin.role');
    }

    /**
     * The holder's role assignments readable in $scope (or stored in exactly
     * $scope, for a write), each with its throughs.
     *
     * One statement, so one snapshot: an assignment is never seen without the
     * throughs it had when it was read. Two reads would let a removal that
     * commits between them show the assignment with no throughs — uncapped.
     *
     * @return array<int, stdClass> rows of {assignment_id, name, throughs: rows of {holder_type, holder_id, role_id, role_name}}
     */
    protected function roleAssignments(Model $holder, ?Scope $scope, bool $exact = false): array
    {
        $pivotTable = config('taxon.tables.taggables', 'taggables');
        $tagTable = config('taxon.tables.tags', 'tags');
        $throughTable = $this->throughsTable();

        $query = Taggable::query()
            ->join($tagTable, "{$tagTable}.id", '=', "{$pivotTable}.tag_id")
            ->leftJoin($throughTable, "{$throughTable}.assignment_id", '=', "{$pivotTable}.id")
            ->leftJoin("{$tagTable} as permixion_ceiling", 'permixion_ceiling.id', '=', "{$throughTable}.role_id")
            ->where("{$pivotTable}.taggable_type", $holder->getMorphClass())
            ->where("{$pivotTable}.taggable_id", $holder->getKey())
            ->where("{$tagTable}.parent_id", $this->rolesCategory()->getKey());

        if ($exact) {
            $this->applyScopeToPivot($query, $pivotTable, $scope);
        } else {
            $this->applyReadScopeToPivot($query, $pivotTable, $scope);
        }

        $rows = $query->toBase()->get([
            "{$pivotTable}.id as assignment_id",
            "{$tagTable}.name as name",
            "{$throughTable}.id as through_id",
            "{$throughTable}.holder_type",
            "{$throughTable}.holder_id",
            "{$throughTable}.role_id",
            'permixion_ceiling.name as role_name',
        ]);

        $assignments = [];

        foreach ($rows as $row) {
            $key = (string) $row->assignment_id;

            $assignments[$key] ??= (object) [
                'assignment_id' => $row->assignment_id,
                'name' => $row->name,
                'throughs' => [],
            ];

            if ($row->through_id !== null) {
                $assignments[$key]->throughs[] = (object) [
                    'holder_type' => $row->holder_type,
                    'holder_id' => $row->holder_id,
                    'role_id' => $row->role_id,
                    'role_name' => $row->role_name,
                ];
            }
        }

        return array_values($assignments);
    }

    protected function isActiveHolder(Model $holder): bool
    {
        return ! $holder instanceof CanBeInactive || $holder->isActiveHolder();
    }

    protected function holderIdentity(Model $holder): string
    {
        return $holder->getMorphClass().'#'.$holder->getKey();
    }

    protected function findHolder(string $type, string $id): ?Model
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::query()->find($id);
    }

    /**
     * The holder's taggable rows under one category (roles or permissions),
     * read with the same scope rules as every other check.
     *
     * @return array<int, stdClass> rows of {assignment_id, name}
     */
    protected function holderTagRows(Model $holder, Tag $category, ?Scope $scope): array
    {
        $pivotTable = config('taxon.tables.taggables', 'taggables');
        $tagTable = config('taxon.tables.tags', 'tags');

        $query = Taggable::query()
            ->join($tagTable, "{$tagTable}.id", '=', "{$pivotTable}.tag_id")
            ->where("{$pivotTable}.taggable_type", $holder->getMorphClass())
            ->where("{$pivotTable}.taggable_id", $holder->getKey())
            ->where("{$tagTable}.parent_id", $category->getKey());

        $this->applyReadScopeToPivot($query, $pivotTable, $scope);

        return $query->toBase()
            ->get(["{$pivotTable}.id as assignment_id", "{$tagTable}.name as name"])
            ->all();
    }

    protected function throughsTable(): string
    {
        return config('permixion.tables.assignment_throughs', 'permixion_assignment_throughs');
    }

    /**
     * The memo of root results. Bound `scoped`, so Octane and queue workers
     * start every request and job with an empty one. Any other long-lived
     * process must flush it per unit of work (flushEffectivePermissions() or
     * app()->forgetScopedInstances()), or bound it with permixion.memo.ttl.
     *
     * @return ArrayObject<string, array{result: EffectivePermissions, expires: int|null}>
     */
    protected function effectiveMemo(): ArrayObject
    {
        return app('permixion.effective-memo');
    }

    /**
     * Per holder and scope, plus every setting the result depends on, so a
     * config change mid-request cannot serve a stale answer.
     */
    protected function memoKey(Model $holder, ?Scope $scope): ?string
    {
        if ($holder->getKey() === null) {
            return null;
        }

        $scopeKey = match (true) {
            $scope instanceof GlobalScope => 'global',
            $scope instanceof Scope => $scope->getScopeType().'#'.$scope->getScopeId(),
            default => 'null',
        };

        return implode('|', [
            $this->holderIdentity($holder),
            $scopeKey,
            json_encode([
                config('permixion.teams.global_fallback'),
                config('permixion.super_admin.enabled'),
                config('permixion.super_admin.role'),
                config('permixion.wildcards.enabled'),
                config('permixion.wildcards.delimiter'),
                config('permixion.through.max_depth'),
            ]),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | User-Tag Operations (name-based, bypassing HasTags slug logic)
    |--------------------------------------------------------------------------
    */

    /**
     * The roles in force for the user in $scope (see roleNamesInForce()).
     *
     * @return array<int, string>
     */
    public function getUserRoleNames(Authorizable $user, ?Scope $scope = null): array
    {
        if (! $user instanceof Model || ! method_exists($user, 'tags')) {
            return [];
        }

        return $this->roleNamesInForce($user, $scope);
    }

    /**
     * The roles stored against the user in $scope, in force or not — what
     * syncRoles() reconciles against. Never use it to authorize.
     *
     * @return array<int, string>
     */
    public function getUserAssignedRoleNames(Authorizable $user, ?Scope $scope = null): array
    {
        if (! method_exists($user, 'tags')) {
            return [];
        }

        $categoryId = $this->rolesCategory()->id;
        $pivotTable = config('taxon.tables.taggables', 'taggables');

        $query = $user->tags()->where('parent_id', $categoryId);
        $this->applyReadScopeToPivot($query, $pivotTable, $scope);

        return $query->pluck('name')->all();
    }

    /**
     * Whether the role is in force for the user in $scope: assigned, the holder
     * active, and the role surviving its throughs and ceilings whole.
     */
    public function userHasRole(Authorizable $user, string $roleName, ?Scope $scope = null): bool
    {
        if (! $user instanceof Model || ! method_exists($user, 'tags')) {
            return false;
        }

        return $this->roleInForce($user, $roleName, $scope);
    }

    /**
     * Whether the role is in force for the user under ANY scope (including
     * unscoped), each assignment judged in its own scope.
     *
     * Useful for "is this user an admin at all?" checks where the current
     * scope context is irrelevant.
     */
    public function userHasRoleInAnyScope(Authorizable $user, string $roleName): bool
    {
        if (! $user instanceof Model || ! method_exists($user, 'tags')) {
            return false;
        }

        $pivotTable = config('taxon.tables.taggables', 'taggables');

        $scopes = $user->tags()
            ->where('parent_id', $this->rolesCategory()->id)
            ->where('name', $roleName)
            ->get(["{$pivotTable}.scope_type", "{$pivotTable}.scope_id"]);

        foreach ($scopes as $row) {
            if ($this->roleInForce($user, $roleName, $this->storedScope($row->scope_type, $row->scope_id))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Scope a stored pivot row names: GlobalScope for an unscoped row.
     */
    protected function storedScope(?string $type, int|string|null $id): Scope
    {
        if ($type === null || $id === null) {
            return GlobalScope::instance();
        }

        return new class($type, $id) implements Scope
        {
            public function __construct(private readonly string $type, private readonly int|string $id) {}

            public function getScopeType(): string
            {
                return $this->type;
            }

            public function getScopeId(): string|int
            {
                return $this->id;
            }
        };
    }

    /**
     * @param  array<int, Model|Role|string>  $through  holders and/or ceiling roles
     *                                                  the assignment acts through
     */
    public function attachRoleToUser(Authorizable $user, string $roleName, ?Scope $scope = null, array $through = []): void
    {
        if (! method_exists($user, 'tags')) {
            return;
        }

        // Resolve every through before writing anything, so a bad one leaves no
        // half-made assignment behind.
        $this->attachRoleWithThroughRows($user, $roleName, $scope, $this->resolveThroughs($through));
    }

    /**
     * Make the roles stored in exactly $scope exactly $roleNames, never widening.
     *
     * A role already held stays in place with its throughs. A role it removes
     * that was capped hands its throughs on to every role it newly assigns, so
     * swapping a capped holder's role keeps the cap. $through applies to every
     * role in $roleNames, like assignRole()'s.
     *
     * @param  array<int, string>  $roleNames
     * @param  array<int, Model|Role|string>  $through
     */
    public function syncUserRoles(Authorizable $user, array $roleNames, ?Scope $scope = null, array $through = []): void
    {
        if (! $user instanceof Model || ! method_exists($user, 'tags')) {
            return;
        }

        $throughRows = $this->resolveThroughs($through);
        $wanted = array_values(array_unique($roleNames));

        Taggable::query()->getConnection()->transaction(function () use ($user, $wanted, $scope, $throughRows): void {
            $held = [];
            $carried = [];

            foreach ($this->roleAssignments($user, $scope, exact: true) as $assignment) {
                if (in_array($assignment->name, $wanted, true)) {
                    $held[] = $assignment->name;

                    continue;
                }

                foreach ($assignment->throughs as $row) {
                    $carried[] = [
                        'holder_type' => $row->holder_type,
                        'holder_id' => $row->holder_id === null ? null : (string) $row->holder_id,
                        'role_id' => $row->role_id,
                    ];
                }

                $this->detachRoleFromUser($user, $assignment->name, $scope);
            }

            foreach ($wanted as $roleName) {
                if (in_array($roleName, $held, true)) {
                    if ($throughRows !== []) {
                        $this->attachRoleWithThroughRows($user, $roleName, $scope, $throughRows);
                    }

                    continue;
                }

                $this->attachRoleWithThroughRows($user, $roleName, $scope, [...$throughRows, ...$carried]);
            }
        });

        $this->flushEffectivePermissions();
    }

    /**
     * @param  array<int, array{holder_type: ?string, holder_id: ?string, role_id: int|string|null}>  $throughRows
     */
    protected function attachRoleWithThroughRows(Authorizable $user, string $roleName, ?Scope $scope, array $throughRows): void
    {
        if (! method_exists($user, 'tags')) {
            return;
        }

        if (config('permixion.strict')) {
            $tag = $this->findRoleOrFail($roleName)->tag();
        } else {
            $tag = $this->findRole($roleName)?->tag()
                ?? $this->createRole($roleName)->tag();
        }

        // The assignment and its throughs are one write: an assignment stored
        // without the throughs it was given would read as uncapped.
        Taggable::query()->getConnection()->transaction(function () use ($user, $tag, $roleName, $scope, $throughRows): void {
            $assignmentId = $this->userTagPivotId($user, $tag->id, $scope);

            if ($assignmentId === null) {
                $user->tags()->attach($tag->id, $this->scopePivotData($scope));
                $assignmentId = $this->userTagPivotId($user, $tag->id, $scope);
            } else {
                // One assignment per holder, role and scope: a capped route and an
                // uncapped one cannot share it. Refuse loudly instead of merging.
                $capped = $this->assignmentIsCapped($assignmentId);

                if ($throughRows !== [] && ! $capped) {
                    throw AssignmentRouteConflict::cappedOntoUncapped($roleName);
                }

                if ($throughRows === [] && $capped) {
                    throw AssignmentRouteConflict::uncappedOntoCapped($roleName);
                }
            }

            if ($throughRows !== [] && $assignmentId !== null) {
                $this->addThroughs($assignmentId, $throughRows);
            }
        });

        $this->flushEffectivePermissions();
    }

    protected function assignmentIsCapped(int|string $assignmentId): bool
    {
        return Taggable::query()->getConnection()
            ->table($this->throughsTable())
            ->where('assignment_id', $assignmentId)
            ->exists();
    }

    /**
     * @param  array<int, mixed>  $through
     * @return array<int, array{holder_type: ?string, holder_id: ?string, role_id: int|string|null}>
     */
    protected function resolveThroughs(array $through): array
    {
        $rows = [];

        foreach ($through as $item) {
            if ($item instanceof Model) {
                if (! $item->exists || $item->getKey() === null) {
                    throw new InvalidArgumentException('A holder an assignment acts through must be saved first.');
                }

                $rows[] = ['holder_type' => $item->getMorphClass(), 'holder_id' => (string) $item->getKey(), 'role_id' => null];

                continue;
            }

            if ($item instanceof Role) {
                $rows[] = ['holder_type' => null, 'holder_id' => null, 'role_id' => $item->tag()->getKey()];

                continue;
            }

            if (is_string($item)) {
                $role = config('permixion.strict')
                    ? $this->findRoleOrFail($item)
                    : ($this->findRole($item) ?? $this->createRole($item));

                $rows[] = ['holder_type' => null, 'holder_id' => null, 'role_id' => $role->tag()->getKey()];

                continue;
            }

            throw new InvalidArgumentException('An assignment acts through a holder model, a Role, or a role name; got '.get_debug_type($item).'.');
        }

        return $rows;
    }

    /**
     * Add throughs to an assignment, skipping any it already has. Throughs are
     * only ever added: more throughs can only narrow, so a write never widens.
     *
     * @param  array<int, array{holder_type: ?string, holder_id: ?string, role_id: int|string|null}>  $rows
     */
    protected function addThroughs(int|string $assignmentId, array $rows): void
    {
        $table = Taggable::query()->getConnection()->table($this->throughsTable());

        foreach ($rows as $row) {
            $exists = (clone $table)
                ->where('assignment_id', $assignmentId)
                ->where('holder_type', $row['holder_type'])
                ->where('holder_id', $row['holder_id'])
                ->where('role_id', $row['role_id'])
                ->exists();

            if (! $exists) {
                (clone $table)->insert($row + [
                    'assignment_id' => $assignmentId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
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
        $this->applyReadScopeToPivot($query, $pivotTable, $scope);

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

        if (! $this->userTagPivotExists($user, $tag->id, $scope)) {
            $user->tags()->attach($tag->id, $this->scopePivotData($scope));
        }

        $this->flushEffectivePermissions();
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
        return $scope instanceof Scope && ! $scope instanceof GlobalScope ? [
            'scope_type' => $scope->getScopeType(),
            'scope_id' => $scope->getScopeId(),
        ] : [];
    }

    /**
     * Exact scope matching: a concrete Scope matches only its own rows;
     * null or GlobalScope matches only unscoped rows. Used by writes
     * (assign/remove/sync) so they never touch assignments outside the
     * requested scope.
     */
    protected function applyScopeToPivot(mixed $query, string $pivotTable, ?Scope $scope): void
    {
        if ($scope instanceof Scope && ! $scope instanceof GlobalScope) {
            $query->where("{$pivotTable}.scope_type", $scope->getScopeType())
                ->where("{$pivotTable}.scope_id", $scope->getScopeId());

            return;
        }

        $query->whereNull("{$pivotTable}.scope_type")
            ->whereNull("{$pivotTable}.scope_id");
    }

    /**
     * Scope matching for READ checks. Like applyScopeToPivot, but when
     * teams.global_fallback is enabled a concrete Scope additionally matches
     * unscoped (global) rows — so a role assigned with no scope applies under
     * every scope. GlobalScope always reads global rows only.
     */
    public function applyReadScopeToPivot(mixed $query, string $pivotTable, ?Scope $scope): void
    {
        if (
            $scope instanceof Scope
            && ! $scope instanceof GlobalScope
            && config('permixion.teams.global_fallback', false)
        ) {
            $query->where(function ($outer) use ($pivotTable, $scope): void {
                $outer->where(function ($scoped) use ($pivotTable, $scope): void {
                    $scoped->where("{$pivotTable}.scope_type", $scope->getScopeType())
                        ->where("{$pivotTable}.scope_id", $scope->getScopeId());
                })->orWhere(function ($global) use ($pivotTable): void {
                    $global->whereNull("{$pivotTable}.scope_type")
                        ->whereNull("{$pivotTable}.scope_id");
                });
            });

            return;
        }

        $this->applyScopeToPivot($query, $pivotTable, $scope);
    }

    protected function userTagPivotExists(Authorizable $user, int|string $tagId, ?Scope $scope): bool
    {
        $query = Taggable::query()
            ->where('tag_id', $tagId)
            ->where('taggable_type', $user->getMorphClass())
            ->where('taggable_id', $user->getKey());

        $this->applyScopeToPivot($query, $query->getModel()->getTable(), $scope);

        return $query->exists();
    }

    protected function userTagPivotId(Authorizable $user, int|string $tagId, ?Scope $scope): int|string|null
    {
        $query = Taggable::query()
            ->where('tag_id', $tagId)
            ->where('taggable_type', $user->getMorphClass())
            ->where('taggable_id', $user->getKey());

        $this->applyScopeToPivot($query, $query->getModel()->getTable(), $scope);

        return $query->toBase()->value('id');
    }

    protected function deleteUserTagPivot(Authorizable $user, int|string $tagId, ?Scope $scope): void
    {
        $query = Taggable::query()
            ->where('tag_id', $tagId)
            ->where('taggable_type', $user->getMorphClass())
            ->where('taggable_id', $user->getKey());

        $this->applyScopeToPivot($query, $query->getModel()->getTable(), $scope);

        // An assignment's throughs go with it (there is no foreign key to
        // cascade), in one transaction: the row must never outlive its throughs.
        $query->getConnection()->transaction(function () use ($query): void {
            $query->getConnection()
                ->table($this->throughsTable())
                ->whereIn('assignment_id', (clone $query)->toBase()->pluck('id')->all())
                ->delete();

            $query->delete();
        });

        $this->flushEffectivePermissions();
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

    /**
     * Insert a role or permission tag inside a savepoint.
     *
     * On PostgreSQL a failed statement aborts the surrounding transaction, so
     * when the caller is already inside one (a seeder, a request wrapped in
     * DB::transaction) the collision lookup that follows a refused insert would
     * itself fail with "current transaction is aborted". The savepoint rolls back
     * only the insert and leaves the caller's transaction usable.
     */
    protected function insertTag(string $name, Tag $category): Tag
    {
        return $category->getConnection()->transaction(fn (): Tag => Tag::create([
            'name' => $name,
            'slug' => $name,
            'parent_id' => $category->id,
            'assignable' => true,
        ]));
    }

    /**
     * The name of the sibling tag already holding a slug, for an error message.
     *
     * Best effort by design: it is called on the way out of a failed insert, and
     * a caller that cannot be told *which* row collided is still better served
     * by a named role than by a raw SQL error.
     */
    protected function tagNameOccupyingSlug(Tag $category, string $slug): ?string
    {
        $existing = $category->children()->where('slug', $slug)->first();

        return $existing instanceof Tag ? $existing->name : null;
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
        $this->flushEffectivePermissions();

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
