# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

> **Versioning note (2026-08-08).** This package was renumbered down to `0.x` to
> signal that its API is still settling. Tags were re-cut at the same commits:
> old `vN.m.p` became `v0.N.<ordinal-within-N>`. Under Composer, `^0.4.0` resolves
> to `>=0.4.0 <0.5.0`, so **every minor release may break** — which is the point.
> It will go to `1.0.0` when the consuming apps ship publicly.

## [0.5.2] - 2026-09-15

Compatibility with Taxon 0.6.x. Verified against Taxon **0.6.0**.

### Changed
- `robinsonryan/taxon` constraint is `^0.5.1|^0.6.0`. Taxon 0.6.0 ships `uuid7` as its
  default `id_type`; this package sets nothing itself, so a consumer's own `taxon.id_type`
  still governs. Consumers on 0.3.x/0.4.x taxon should move to 0.5.1+ first
- Taxon is consumed by tag from GitHub (a `vcs` repository) instead of a local `path`
  repository; the DDEV mount that served the path repository is gone

## [0.5.1] - 2026-08-17

Compatibility with Taxon 0.4.x and 0.5.x. Verified against Taxon **0.5.1**.

**What the acceptance bar was.** Seeders and tests run against the new Taxon and
produce the grants they claim to — proven by `tests/Feature/SeederAcceptanceTest.php`,
which runs application-shaped seeder classes through `db:seed`, and by the full
suite on PostgreSQL 18 in Taxon's `uuid7` mode. Nothing here was tested against a
pre-existing installation's data, and this release makes no claim about one: every
consumer is greenfield.

### Changed
- **`robinsonryan/taxon` widens to `^0.3.0|^0.4.0|^0.5.0`.** The `^0.3.0` floor cut
  in 0.5.0 is read by Composer as `>=0.3.0 <0.4.0`, so it excluded every Taxon
  release from 0.4.0 onward — including 0.5.0, which is where PostgreSQL took over
  generating `tags.id` and `taggables.id` from their `uuidv7()` column defaults. Any
  application on both packages had to choose one of them to hold back. The floor
  stays at 0.3.0 for the schema reason recorded under 0.5.0 below; nothing about the
  API surface argues for narrowing it.

- **A role or permission now stores its name verbatim in the tag's `slug` column**
  (`Permixion::createRole()`, `Permixion::createPermission()`), where it previously
  stored `Str::slug($name)`. Identity was already the name — this makes the column
  that the database indexes agree with it.

  Taxon 0.4.0's `harden_tags_unique_index_against_nulls` migration rebuilt
  `tags_unique_slug_parent_tenant` over `COALESCE`'d nulls, so two sibling tags may
  no longer share a slug. `Str::slug` is lossy: `account.view` and `accountview` both
  reduce to `accountview`, so two legitimately distinct permissions collided on
  insert with a `UniqueConstraintViolationException`. Verbatim is the only mapping
  injective on the name, which leaves that index enforcing exactly the identity this
  package documents — and enforces it in the database rather than only in the
  `where('name', …)->exists()` check that precedes each insert.

  **What this means for rows written by an earlier version.** They keep the
  slugged value they were given — this release changes what is *written*, not what
  is stored, and it ships no data migration. Reads are unaffected, because every
  lookup in this package is by `name`. Writes are not guaranteed to be: a new
  name whose verbatim form lands on an old row's slugged value will be refused by
  the database. That is now a named error rather than a raw SQL one (below), but it
  is still an error. **No upgrade of an existing installation was tested**, so treat
  the greenfield case as the only one this release stands behind.

### Added
- **`RoleSlugCollision` and `PermissionSlugCollision`.** `createRole()` and
  `createPermission()` catch the unique-constraint violation the hardened index
  raises and rethrow it naming the role or permission that was refused and, where it
  can be found, the sibling row holding that slug. The database error is kept as the
  cause. Previously the caller got Taxon's raw `UniqueConstraintViolationException`:
  an index name and a slug value, neither of which any Permixion API accepts or
  returns. A same-name create is unchanged — that is still caught before the insert
  and still throws `RoleAlreadyExists` / `PermissionAlreadyExists`.

- **A seeder acceptance suite** (`tests/Feature/SeederAcceptanceTest.php`). Role and
  permission creation, permission syncing, user assignment and re-running are
  exercised through `db:seed` with seeder classes shaped the way a consuming
  application's are, rather than by calling the engine directly. Two shapes are
  deliberate: a user created with `createQuietly()`, and a whole run inside
  `Model::withoutEvents()`. Both suppress the model events Taxon 0.5.0 used to flush
  a deferred tag assignment — the defect Taxon 0.5.1 fixed — and seeders are exactly
  where they live. This package assigns through the `tags()` relation rather than a
  tag attribute, so it was never exposed; the cases stay to keep proving it.

- **A check that the Taxon this suite runs against is the Taxon it claims**
  (`tests/Feature/TaxonVersionTest.php`). Locally Taxon is a `path` repository whose
  version is asserted by an `options.versions` pin in `composer.json`. The pin is now
  compared both against the declared `require` constraint and against the checkout's
  own changelog, so a pin left behind fails the suite. Leaving that unchecked is what
  let Taxon 0.4.0's index hardening run here unnoticed for a release.

- **A PostgreSQL test suite for Taxon's `uuid7` mode** (`tests/Postgres/`), covering
  role and permission writes where every key — tag, pivot and consumer model — comes
  from a `uuidv7()` column default. It pins that keys are database-generated (drop
  the default and role creation fails — on `tags` **and** on `taggables`, since the
  pivot is a second write path), that pivot rows carry the user's UUID, and that role
  assignment on an **unsaved** model still fails loudly rather than writing a keyless
  pivot row: Taxon 0.5.0 defers a *tag attribute* assigned before a model has a key,
  and this package writes through the `tags()` relation, which that deferral does not
  cover. The rest of the suite still runs on SQLite in-memory, where a column-type
  defect is unfalsifiable — see the 0.5.0 note below.

  It builds and drops tables, so it runs in a database of its own — `db_testing`,
  created on first run — and refuses to start if that name resolves to the
  development database. Override with `PGDATABASE_TESTING`.

## [0.5.0] - 2026-08-08

### Changed (BREAKING)
- **`robinsonryan/taxon` is now constrained to `^0.3.0`.** It was declared as
  `^2.0|@dev`, and that stopped meaning anything the moment Taxon renumbered itself
  down to `0.x` on 2026-08-08 and re-cut its tags at the same commits (`v2.0.0`
  became `v0.2.0`, and so on). No version can ever satisfy `^2.0` again, so the only
  live branch of that `|` was the bare `@dev` — and a bare `@dev` is `*@dev`: **any**
  version, at **any** stability. So the package that stores every byte of its state
  in Taxon was, in effect, declaring no constraint on Taxon at all. Consumers did not
  get an error; they got whichever Taxon their own `composer.json` happened to pull
  in, and this package quietly accepted it.

  `^0.3.0` — Composer reads it as `>=0.3.0 <0.4.0` — is the floor, and the reason is
  schema, not API. Permixion owns no migrations and no tables. Every role and
  permission assignment it makes is a row in Taxon's `taggables` table, written
  through `$user->tags()->attach(...)`, and Taxon 0.3.0 is the release that made that
  table's `taggable_id` column follow the *host application's* key type instead of
  emitting `uuidMorphs()` unconditionally. Below 0.3.0, on PostgreSQL with Taxon's
  default `incrementing` id type, `taggable_id` is a genuine `uuid` column, so
  `assignRole()` against an integer-keyed user model dies on insert; and Taxon's
  `uuid7` migration branch could not run on PostgreSQL at all. Neither failure is
  reachable from this package's own test suite, which runs on SQLite, where `uuid`,
  `bigint` and `varchar` all collapse into the same loose affinity — which is exactly
  how the defect survived this long unnoticed on both sides.

  The API surface argues for nothing narrower and nothing wider. Permixion consumes
  `Models\Tag`, `Models\Taggable`, `Contracts\Scope`, `HasTags`,
  `Concerns\CanScopeTags`, `TaxonServiceProvider` and the `taxon.tables.taggables`
  config key; every one of those has existed, unchanged in the ways this package
  depends on, since Taxon 0.1.0. Picking a lower floor would have been defensible on
  a pure symbol audit and wrong in production.

  **Breaking for any consumer sitting on Taxon `0.2.x`.** Under Composer's
  caret-on-zero rule `^0.2.1` and `^0.3.0` are disjoint, so `composer update` will
  refuse to resolve until that consumer moves its own Taxon constraint up. The move
  is worth making on its own merits for any PostgreSQL application: re-publish
  Taxon's migrations (`--tag=taxon-migrations --force`) before a fresh install, or,
  where the broken table is already live, write a migration altering
  `taggables.taggable_id` to `bigint`. On MySQL and SQLite nothing is required —
  `uuidMorphs` degraded to a string column there and integer keys were being stored
  without complaint.

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

### Development
- **The `../taxon` path repository now declares a version.** Composer guesses a path
  package's version from git, and it prefers the checked-out *branch* over a tag
  sitting on the same commit — so the local Taxon checkout advertised itself as
  `dev-master` even while parked exactly on `v0.3.0`. That satisfied the old `@dev`
  and satisfies nothing real, which meant the honest constraint above could not be
  installed locally at all. The repository entry now carries
  `options.versions: { "robinsonryan/taxon": "0.3.x-dev" }`, which labels the checkout
  as the 0.3 development line: live edits to `../taxon` are still picked up without a
  `composer update`, and `^0.3.0` resolves. This is local development wiring only — it
  has no effect on a consumer, which resolves Taxon from its own repository and real
  tags. It does need bumping by hand when Taxon opens its 0.4 line.

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
