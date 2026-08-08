# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **Versioning note (2026-08-08).** This package was renumbered down to `0.x` to
> signal that its API is still settling. Tags were re-cut at the same commits:
> old `vN.m.p` became `v0.N.<ordinal-within-N>`. Under Composer, `^0.4.0` resolves
> to `>=0.4.0 <0.5.0`, so **every minor release may break** — which is the point.
> It will go to `1.0.0` when the consuming apps ship publicly.

## [Unreleased]

### Changed (BREAKING)
- **Laravel 11 support dropped.** `illuminate/auth`, `illuminate/contracts` and
  `illuminate/support` narrow from `^11.0|^12.0|^13.0` to `^12.0|^13.0`, and
  `orchestra/testbench` from `^9.0|^10.0|^11.0` to `^10.0|^11.0`.

  The `^11.0` was a promise nobody could keep. Testbench 9 is the only thing that
  installs Laravel 11, and it caps at PHPUnit 11; this package's `pestphp/pest ^4.0`
  requires PHPUnit 12. Those two cannot co-exist, so a Laravel 11 test harness has
  never resolved here — not in CI, not locally, not once. Every green suite this
  package has ever run was Laravel 12 or 13. Advertising 11 meant shipping an
  untested compatibility claim, and leaving Testbench 9 in `require-dev` declared a
  harness that can no longer be installed.

  **Breaking for any consumer pinned to Laravel 11**: `composer update` will now
  refuse to resolve. Nothing in the package's behaviour changed — the code may well
  still work under 11 — but it is no longer claimed, because it was never verified.
  Stay on `0.4.0` if you need the old constraint, and treat it as unverified there
  too.

## [0.4.0] - 2026-08-08

### Changed (BREAKING)
- **`HasRoles::can()` no longer answers questions that carry a policy subject.**
  A permission is a *name*: it can express "may update posts at all", never "may
  update **this** post". Previously `can('update', $post)` ignored `$post` entirely
  and returned `true` for anyone holding an `update` permission — silently skipping
  the policy, including any ownership or tenancy clause it contained. Calls that
  carry a subject (a model, a class-string, a DTO) are now delegated to the parent
  `can()` so the policy decides.

  Unchanged: bare ability checks (`can('posts.create')`) still resolve from
  permissions, and a `Scope` argument is still a scope, not a subject. This
  short-circuit was never governed by `register_gate` and had no opt-in guard, so
  the narrowing is unconditional. It can only remove grants, never add them.

  *This has no analogue in `spatie/laravel-permission`, which does not override
  `can()` at all. The narrowing moves toward parity, not away from it.*

### Added
- **`permixion.gate.defer_to_policies`** (default `false`). When enabled, the
  `Gate::before` hook returns `null` for any check carrying a policy subject, so
  `Gate::authorize('update', $post)` is answered by the policy rather than by a
  permission name. Subject-free checks still resolve from permissions, and the
  super-admin branch is deliberately unaffected — it remains unconditional god-mode.

  The default preserves today's behaviour, which is byte-for-byte what
  `spatie/laravel-permission` does. Enable it when your policies discriminate
  between instances of the same model. Before enabling, confirm every model you
  authorize has a policy: abilities that relied on the short-circuit will now be
  decided by the policy, or denied if none exists.
- `Permixion::argumentsCarryPolicySubject()`, the single shared definition of what
  counts as a policy question. Both short-circuits consult it so they cannot drift.

### Fixed
- The `Gate::before` closure now accepts Laravel's third `$arguments` parameter.
  It previously declared only `($user, $ability)`, so the subject was unreachable.

### Documentation
- README now documents that the `Gate::before` hook requires the user model to
  explicitly `implements HasPermissions` — using the `HasRoles` trait alone is not
  enough. This is intentional (declaring the contract is how a model consents to
  Permixion outranking its policies), but it was previously undocumented while
  `register_gate` defaulted to `true`, so the feature advertised itself as on while
  being inert for anyone following the README's example.

### Tests
- 33 → 88 tests. `Gate::before`, `can()`, all three middlewares, `hasExactRoles()`
  and the `permixion.strict => false` branch had no coverage at all; all are now
  characterized, both flag states included. The suite had never executed the
  `Gate::before` hook, because the `User` fixture used the trait without the
  contract — the same gap the README had.

## [0.3.1] - 2026-08-07

### Added
- `GlobalScope` sentinel, `teams.global_fallback`, and `hasRoleAnywhere()`.

## [0.3.0] - 2026-07-xx

### Changed (BREAKING)
- Laravel 13 / PHP 8.4 support; hardened to Larastan level 8.
- Requires `robinsonryan/taxon` `^0.2.0`.

### Also folded into 0.4.0 (previously unreleased)

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

## [0.2.0] - 2026-05-06

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
- Existing test suite now passes (was 9/16 in v0.1.0). Added a regression
  suite (`tests/Feature/NameIdentityTest.php`) covering dotted permission
  names, slug-collision avoidance, wildcard matching against dotted names,
  and verbatim role round-tripping.

### Migration from 0.1.x
This is a behavior change, not a public-API rename — most call sites do not
need code changes. Verify:
- Anywhere reading `getRoleNames()` / `getPermissionNames()` and expecting
  slug-form output. Switch to expecting verbatim names.
- Anywhere using a permission name with a `Str::slug`-incompatible character
  (`.`, `:`, `|`, `/`). Those now work correctly; collisions that v0.1.0
  silently created may surface as duplicate tags in the DB. Audit the
  `tags` table for entries under the permissions/roles categories whose
  `name` and `slug` no longer agree.

## [0.1.0] - 2024-01-12

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
