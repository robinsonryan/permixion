# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
