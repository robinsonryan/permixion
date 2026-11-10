# Permixion

Role and permission management for Laravel using Taxon.

Permixion provides a Spatie-like API for managing roles and permissions, powered by the flexible [Taxon](https://github.com/robinsonryan/taxon) tagging system.

## Features

- 🎭 **Familiar API** - Drop-in replacement for Spatie Laravel Permission
- 🏷️ **Taxon-powered** - All data stored via Taxon's tag system
- 🏢 **Team/Context Scoping** - First-class support for multi-tenant permissions
- ⚡ **Performance** - Built-in caching for role-permission lookups
- 🔍 **Wildcard Permissions** - `posts.*` matches `posts.create`, `posts.edit`, etc.
- 🛡️ **Laravel Gate Integration** - Works seamlessly with Laravel's authorization
- 🎨 **Blade Directives** - `@role`, `@permission`, `@hasanyrole`, etc.
- 🚦 **Middleware** - Protect routes with role/permission checks

## Installation

```bash
composer require robinsonryan/permixion
```

Publish the configuration:

```bash
php artisan vendor:publish --tag=permixion-config
```

Run the migrations. Roles and permissions live in Taxon's tables; permixion adds
one table of its own, `permixion_assignment_throughs`, for [acting for](#acting-for).
It loads automatically; publish it (`--tag=permixion-migrations`) only if you want
to own it.

```bash
php artisan migrate
```

## Quick Start

```php
use RobinsonRyan\Permixion\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles;
}
```

That gives you roles, permissions, the Blade directives, and the middleware.

It does **not** enable the `Gate::before` short-circuit. That one extra step is
deliberate — see [Gate integration](#gate-integration) below.

### Create Roles and Permissions

```php
use RobinsonRyan\Permixion\Facades\Permixion;

// Create roles
Permixion::createRole('admin');
Permixion::createRole('editor', ['posts.create', 'posts.edit']);

// Create permissions
Permixion::createPermission('posts.delete');
Permixion::createPermission('users.manage');

// Assign permissions to roles
$adminRole = Permixion::findRole('admin');
$adminRole->givePermissionTo(['posts.delete', 'users.manage']);
```

### Assign Roles to Users

```php
$user = User::find(1);

// Assign a role
$user->assignRole('editor');

// Assign multiple roles
$user->assignRole(['editor', 'moderator']);

// Remove a role
$user->removeRole('editor');

// Sync roles
$user->syncRoles(['admin']);
```

### Check Permissions

```php
// Check if user has a role
if ($user->hasRole('admin')) {
    //
}

// Check if user has any of the roles
if ($user->hasAnyRole(['admin', 'editor'])) {
    //
}

// Check if user has a permission
if ($user->hasPermissionTo('posts.delete')) {
    //
}

// Check via Laravel Gate
if ($user->can('posts.delete')) {
    //
}

// Scoped: a bare scope and [$scope] mean the same thing
$user->can('posts.delete', $team);
```

### Team/Context Scoping

```php
$team = Team::find(1);

// Assign role within a team context
$user->assignRole('admin', $team);

// Check role within team context
if ($user->hasRole('admin', $team)) {
    //
}

// Check permission within team context
if ($user->hasPermissionTo('posts.delete', $team)) {
    //
}
```

#### Global (unscoped) assignments

When a scope resolver is configured, a `null` scope argument means "resolve
the current scope". To target the **unscoped/global** assignment explicitly,
pass `GlobalScope::instance()`:

```php
use RobinsonRyan\Permixion\GlobalScope;

// Assign account-wide, regardless of any resolvable scope
$user->assignRole('owner', GlobalScope::instance());

// Check global rows only
$user->hasRole('owner', GlobalScope::instance());

// Check across all scopes (scoped or global), ignoring context
$user->hasRoleAnywhere('admin');
```

By default a scoped check matches only that exact scope. Enable
`teams.global_fallback` to make globally-assigned roles/permissions apply
under every scope (spatie/laravel-permission teams semantics):

```php
// config/permixion.php
'teams' => [
    // ...
    'global_fallback' => true,
],

$user->assignRole('owner', GlobalScope::instance());
$user->hasRole('owner', $team);   // true with global_fallback, false without
```

Writes (`assignRole`/`removeRole`/`syncRoles`) always target the exact scope
they are given — `global_fallback` only widens reads.

### Acting for

Some holders act **for** someone else and must hold no more than what they act
for: an AI Agent acting for the person who owns it, an API key acting for its
app, a firm's staffer acting under what the client granted the firm, a key
restricted to drafting. Give the assignment a list of **throughs**:

```php
// The agent is an accountant on $company — capped by what $owner may do there.
$agent->assignRole('accountant', $company, [$owner]);

// A ceiling role: never more than 'drafter' grants, whatever 'accountant' holds.
$key->assignRole('accountant', $company, [$app, 'drafter']);
```

A through is a **holder** (any model using `HasRoles`; it caps the assignment to
what that holder may do in the same scope, recursively) or a **role** (a fixed
ceiling: a role name or `Role`). Several throughs all apply.

What a holder may do in a scope — its **effective permissions** — is the union,
over its assignments there, of *the role's permissions ∩ every through's
effective permissions*, plus its direct permissions. "The lesser of two roles"
is therefore the permissions both have in common: for nested roles it is the
lower role, and it works just as well for roles that are not nested.

```php
$agent->effectivePermissions($company);   // Collection of permission names
$agent->hasPermissionTo('ledger.post', $company);
$agent->can('ledger.post', $company);
Gate::forUser($agent)->allows('ledger.post');
```

`hasPermissionTo()`, `can()`, the `Gate::before` hook, the `permission`
middleware and the `@permission` directives decide from effective permissions,
and `getAllPermissions()` lists them.

**Role questions ask whether a role is in force**, not merely assigned:
`hasRole()` and its family (`hasAnyRole`, `hasAllRoles`, `hasExactRoles`,
`hasRoleAnywhere`, `getRoles`, `getRoleNames`), the `role` and
`role_or_permission` middleware and the `@role` directives. A role is in force
when the holder is active and the role survives its throughs and any
`withCeiling()` whole — every permission of the role is still granted. A role
with no permissions is in force only when nothing caps it; the super-admin role
only when the holder is an uncapped super admin. An Agent holding `accountant`
through a viewer owner does not pass `hasRole('accountant')`.
`getAssignedRoleNames()` lists what is stored, in force or not — for showing
assignments, never for authorizing.

**Everything fails closed.** Each of these caps to nothing rather than granting:

- a cycle (a holder already on the chain contributes nothing);
- a chain deeper than `permixion.through.max_depth` (default 5);
- a through holder or ceiling role that has been deleted;
- an **inactive** holder — see below.

**Super admin.** An uncapped super-admin through holder imposes no cap. A
super-admin *role held through a capped holder* is not god-mode: it is capped
like any other role.

**Writes never widen.** Re-assigning a role with throughs adds them to the
assignment (narrowing it further); re-assigning with none leaves existing ones in
place; `syncRoles()` keeps the throughs on roles it retains. To change a cap,
`removeRole()` and assign again. One holder holds a role once per scope, so two
*routes* to the same role (a direct membership and a capped firm route) are one
assignment, capped by every through given — narrower, never wider.

#### Inactive holders

A revoked key or Agent implements `CanBeInactive`. While inactive it has no
permissions at all (super admin included), and anything acting through it is
capped to nothing. Its assignments stay in place, so re-activating restores them.

```php
use RobinsonRyan\Permixion\Contracts\CanBeInactive;

class AgentKey extends Model implements CanBeInactive /* … */
{
    public function isActiveHolder(): bool
    {
        return $this->revoked_at === null;
    }
}
```

#### A per-request ceiling — `withCeiling()`

When a request asks for less than the holder has — an app passing "act as a
viewer" for the person behind it — narrow a copy for this request only:

```php
$acting = $key->withCeiling('viewer');   // $key itself is untouched

Gate::forUser($acting)->allows('ledger.post');   // false
```

Ceilings stack (they intersect) and never widen. They live on that instance only:
nothing is persisted, and a model re-fetched from the database — a queued job's,
for one — has none.

#### Holders that are not users

Any Eloquent model can hold roles: use `HasRoles` and Taxon's `HasTags`, and
satisfy the framework's `Illuminate\Contracts\Auth\Access\Authorizable`
contract (the `can()` that `HasRoles` supplies is enough). Declare
`HasPermissions` if the `Gate::before` hook should answer for it. Check it with
`Gate::forUser()`:

```php
class Agent extends Model implements Authorizable, HasPermissions, CanBeInactive
{
    use HasRoles, HasTags;
}

Gate::forUser($agent)->allows('ledger.post');
```

If the model also uses the framework's `Authorizable` *trait*, both define
`can()`; say `HasRoles::can insteadof Authorizable`, or `$key->can()` bypasses
the cap.

The hook reads the **ambient** scope, never one passed in the gate arguments. When
the scope comes from tenancy rather than a route parameter, use the `callback`
resolver:

```php
// config/permixion.php
'teams' => [
    'enabled' => true,
    'resolver' => 'callback',
    'callback' => fn () => app(CurrentCompany::class)->get(),
],
```

#### Caching

Effective permissions are memoised for one request per holder and scope, and
never across requests (the memo is bound `scoped`, so Octane and queue workers
start each request and job empty). Every permixion write clears it. If you
change something mid-request that permixion cannot see — revoke a through holder,
or write a taggable row directly — call `Permixion::flushEffectivePermissions()`
before checking again in the same request.

### Blade Directives

```blade
@role('admin')
    <p>You are an admin!</p>
@endrole

@permission('posts.create')
    <a href="{{ route('posts.create') }}">Create Post</a>
@endpermission

@hasanyrole(['admin', 'editor'])
    <p>You have editorial access</p>
@endhasanyrole
```

### Middleware

```php
Route::middleware(['role:admin'])->group(function () {
    //
});

Route::middleware(['permission:posts.delete'])->group(function () {
    //
});

Route::middleware(['role_or_permission:admin|posts.delete'])->group(function () {
    //
});
```

## Gate integration

With `register_gate => true` (the default), Permixion installs a `Gate::before`
hook. Laravel runs those **ahead of every policy**, so a non-null return means the
policy never executes.

Because that is a large promise, it is opt-in **per model**. The hook ignores any
user that does not explicitly declare the contract — using the trait alone is not
enough:

```php
use RobinsonRyan\Permixion\Contracts\HasPermissions;
use RobinsonRyan\Permixion\Traits\HasRoles;

class User extends Authenticatable implements HasPermissions
{
    use HasRoles;
}
```

Declaring `HasPermissions` is how a model says *"this package may outrank my
policies."* If you never declare it, `register_gate` has no effect — which is the
safe default, not a bug.

### Permissions are coarse — `defer_to_policies`

A permission is a **name**. It can express *"may update posts at all"*; it can
never express *"may update **this** post"*. So by default, a user holding a
permission called `update` is granted `update` on **every** instance — the policy,
including any ownership or tenancy clause it contains, is skipped entirely.

That matches `spatie/laravel-permission`, and it is fine when permissions are your
whole authorization model. It is **not** fine when your policies discriminate
between instances. In that case:

```php
// config/permixion.php
'gate' => [
    'defer_to_policies' => true,
],
```

With it on, any check that carries a subject — `can('update', $post)`,
`Gate::authorize('update', $post)` — is handed to the policy. Subject-free checks
still resolve from permissions, and super admin is unaffected.

Two things to know before enabling it:

- It can only ever **remove** grants, never add them. The failure mode is a visible
  403, not silent over-permission.
- Confirm every model you authorize actually **has a policy**. Abilities that were
  relying on the short-circuit will now be answered by the policy — or denied if
  none exists.

> **Naming tip.** Never name a permission after a Laravel ability verb (`view`,
> `update`, `delete`, `create`). Namespace them — `posts.update` — and a permission
> can't collide with a policy ability in the first place.

## Documentation

See the [docs](./docs) directory for detailed documentation:

- [Installation](./docs/installation.md)
- [Basic Usage](./docs/basic-usage.md)
- [Teams & Scoping](./docs/teams.md)
- [Blade Directives](./docs/blade-directives.md)
- [Middleware](./docs/middleware.md)
- [API Reference](./docs/api-reference.md)

## Requirements

- PHP 8.2+
- Laravel 12.x or 13.x
- robinsonryan/taxon ^0.5.1|^0.6.0 (`^2.0` no longer exists — Taxon
  renumbered down to `0.x` on 2026-08-08 and re-cut its tags at the same commits)
- PostgreSQL 18+ if you run Taxon in `uuid7` mode, whose keys come from a
  `uuidv7()` column default

## License

MIT License. See [LICENSE.md](LICENSE.md) for details.
