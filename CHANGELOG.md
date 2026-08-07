# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- `HasRoles::can()` no longer fatals on ability forms it cannot resolve. Laravel's
  `Authorizable::can()` accepts `iterable|string|UnitEnum`, but `can()` forwarded the
  ability straight into `hasPermissionTo(string|Permission)`, so an iterable or enum
  ability raised a `TypeError` instead of being authorized. Such abilities now bypass
  Permixion's own check and go to the parent `can()`. **Permixion does not widen what
  they grant** — it does not, for example, resolve a `BackedEnum` to its `->value` and
  match a permission by that name.
- `HasRoles::can()` no longer fatals when `$arguments` is a non-array. `$user->can('update', $dto)`
  evaluated `$arguments[0]` on an arbitrary object, raising
  `Cannot use object of type X as array` for anything not implementing `ArrayAccess`.
  The scope-sniffing branch now subscripts only arrays and `ArrayAccess`. Behaviour is unchanged for
  every input that previously worked (arrays, class-strings, Eloquent models).

### Changed
- Minimum PHP lowered from `^8.3` to `^8.2`, matching `robinsonryan/taxon` and the harness
  package baseline. Removed the PHP 8.3-only `#[\Override]` attribute from
  `PermixionServiceProvider::register()`, which was unusable on the new floor.
- Quality gate now runs Rector (`composer quality` gained `@refactor:check`); PHPStan
  analyses `tests/Fixtures` alongside `src`, which is what surfaced the `can()` defects
  above. No public API changed.

## [2.0.0] - 2026-05-06

### Changed (BREAKING)
- Roles and permissions are now identified by `Tag.name` (verbatim) instead of
  `Tag.slug`. Previously, all lookup paths went through `Str::slug`, which
  silently mangled delimiter-bearing identifiers — `'account.view'` became
  `'accountview'`, `'work_order.assign'` became `'work-orderassign'` — and
  caused collisions (`account.view` + `accountview` resolved to the same
  tag). The default wildcard delimiter (`'.'`) was unusable in practice for
  the same reason.
- `getRoleNames()`, `getPermissionsForRole()`, and `getPermissionNames()` now
  return verbatim names. Code that depended on slug-form output (e.g.
  `'shop-manager'` instead of `'shop_manager'`) needs to update its
  expectations.
- `HasRoles` trait operations (`assignRole`, `removeRole`, `hasRole`,
  `givePermissionTo`, etc.) now route through new name-based service helpers
  on `Permixion` (`attachRoleToUser`, `userHasRole`,
  `attachPermissionToUser`, …) instead of Taxon's `HasTags` slug helpers.
- `Models\Role::givePermissionTo` and `syncPermissions` now attach permission
  tags by ID (the previous code passed slug strings into Tag's top-level
  `tag()` helper, which created duplicate top-level tags rather than
  attaching the existing permission tag).
- `Permixion::userHasPermission` now checks all of a user's roles in scope
  (was previously checking only the first role returned by
  `getTagValueIn`).

### Fixed
- Existing test suite now passes (was 9/16 in v1.0.0). Added a regression
  suite (`tests/Feature/NameIdentityTest.php`) covering dotted permission
  names, slug-collision avoidance, wildcard matching against dotted names,
  and verbatim role round-tripping.

### Migration from 1.x
This is a behavior change, not a public-API rename — most call sites do not
need code changes. Verify:
- Anywhere reading `getRoleNames()` / `getPermissionNames()` and expecting
  slug-form output. Switch to expecting verbatim names.
- Anywhere using a permission name with a `Str::slug`-incompatible character
  (`.`, `:`, `|`, `/`). Those now work correctly; collisions that v1.0.0
  silently created may surface as duplicate tags in the DB. Audit the
  `tags` table for entries under the permissions/roles categories whose
  `name` and `slug` no longer agree.

## [1.0.0] - 2024-01-12

### Added
- Initial release
- Role and permission management using Taxon under the hood
- Spatie-compatible API
- Team/context-based scoping support
- HasRoles trait for User models
- Wildcard permission matching
- Super admin role support
- Laravel Gate integration
- Blade directives (@role, @permission, etc.)
- Middleware (role, permission, role_or_permission)
- Artisan commands for role and permission management
- Cache support for performance
