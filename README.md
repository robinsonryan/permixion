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

## Quick Start

```php
use RobinsonRyan\Permixion\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles;
}
```

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
- Laravel 11.x or 12.x
- robinsonryan/taxon ^1.1

## License

MIT License. See [LICENSE.md](LICENSE.md) for details.
