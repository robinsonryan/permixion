# Permixion

Role and permission management for Laravel with a spatie-shaped API, storing
everything as tags in `robinsonryan/taxon` rather than in tables of its own.
Roles and permissions are first-class scoped assignments, so the same user can
hold different roles per team/tenant.

Composer name: `robinsonryan/permixion` — a **library**, not an application.

## Conventions

@.claude/constitution.md
@.claude/stack.md

> Which rules load is set by `harness.yaml` and assembled into `.claude/stack.md`
> (`harness stack apply`); a package with a frontend half adds the frontend layers
> there (today: `yikes`, `four-corners`). Don't import convention files by hand.

> `.claude/` is a set of **harness symlinks** and is gitignored — a fresh clone has
> none of them and the imports above resolve to nothing. If a convention file is
> missing, restore the link rather than guessing:
> `harness stack apply`

## The sharpest edge: two authorization short-circuits

Permixion can answer authorization questions **before** your policies do. There
are two independent doors, and getting them wrong hands users grants their
policies were written to refuse. Read this before touching anything under
`src/Traits/HasRoles.php` or `registerGatePermissions()`.

The full investigation — including three corrections to an earlier, wronger
version of this story — is in `docs/gate-before-review.md`. Read it before
changing either mechanism.

### Door 1 — the `Gate::before` hook

`PermixionServiceProvider::registerGatePermissions()` installs a `Gate::before`
closure when `permixion.register_gate` is `true` (the default). Laravel runs
`before` hooks **ahead of every policy**; a non-null return means the policy
never executes at all.

The closure is `function ($user, string $ability, array $arguments = []): ?true`
and runs in this order:

1. `$user instanceof HasPermissions` — **the contract, not the trait.** Using
   `HasRoles` alone does not satisfy it and the hook returns `null`. This is
   deliberate: declaring `RobinsonRyan\Permixion\Contracts\HasPermissions` is how
   a model consents to this package outranking its policies. Do not "tidy" this
   into a `method_exists()` capability check — that one-line change switches the
   short-circuit on in every consumer that only uses the trait. Two fixtures
   (`tests/Fixtures/User.php` without the contract, `GateUser.php` with it) pin
   both sides.
   The scope answered is the one named in `$arguments` (a bare `Scope` or one
   first in the array — `Permixion::scopeFromArguments()`), else the ambient one
   (since 0.6.0; before, always ambient, which re-granted a scoped `can()` that
   `HasRoles::can()` had refused). Pinned in `GateBeforeTest`.
2. Super admin — if the user's effective permissions in that scope are
   *unlimited* (`super_admin.enabled`, `super_admin.role` held with no through, by
   an active holder, under no `withCeiling()`), return `true`. God-mode,
   deliberately **not** narrowed by `defer_to_policies`.
3. `defer_to_policies` — see below.
4. `$user->hasPermissionTo($ability, $scope)` → `true`, else `null`.

**It is model-blind, and that is the whole problem.** The closure receives
`$arguments`, but only ever asks *whether* a policy subject is present — never
*which* one. So with the default config, `can('update', $postA)` and
`can('update', $postB)` are indistinguishable: any user holding a permission
literally named `update` is granted `update` on **every** instance, and the
policy — including the ownership or tenancy clause it exists to enforce — is
skipped entirely.

This is inherited behaviour, not a Permixion invention:
`spatie/laravel-permission`'s `PermissionRegistrar` does the same thing, which is
why the default preserves it.

**Two return-type facts that bound the blast radius**, both pinned by tests:
the `?true` return makes a hard deny structurally impossible (a `Gate::before`
returning `false` would refuse every unrelated gate in the app), and the read
path returns `bool` rather than throwing, so an unknown ability falls through
instead of exploding inside someone else's gate. The `permixion.strict` throws
live only on the **write** paths.

### How a consumer configures around it

Three levers, coarse to fine:

- **`permixion.register_gate => false`** — the hook is never installed. Blade
  directives, middleware, `hasRole()`/`hasPermissionTo()` and the `can()` override
  all still work.
- **Never declare `implements HasPermissions`** — same practical effect for that
  model, whatever `register_gate` says.
- **`permixion.gate.defer_to_policies => true`** (added 0.4.0, default `false`) —
  the hook returns `null` for any check whose `$arguments` carry a non-`Scope`
  subject, so `Gate::authorize('update', $post)` is answered by the policy.
  Subject-free checks (`can('posts.create')`) still resolve from permissions, and
  super admin is still unconditional. It can only ever **remove** grants, so the
  failure mode is a visible 403, not silent over-permission — but before enabling
  it, confirm every model you authorize actually *has* a policy, because abilities
  that were relying on the short-circuit will now be decided by one or denied.

**And the rule that makes all of this moot: never name a permission after a bare
Laravel ability verb.** Namespace them — `posts.update`, not `update` — and a
permission cannot collide with a policy ability in the first place. Cheaper and
more durable than any of the machinery above.

### Door 2 — `HasRoles::can()`

The trait overrides `can()`. This has **no analogue in spatie**, is **not**
governed by `register_gate`, has no contract guard, and applies wherever the trait
is used. Since 0.4.0 it is narrowed unconditionally: if `$arguments` carry a
policy subject it delegates straight to `parent::can()`; otherwise it tries
`hasPermissionTo()` and falls back to the parent. Since 0.6.0 a bare Scope
argument scopes it exactly as `[$scope]` does (`Permixion::scopeFromArguments()`).

Both doors share one definition of "is this a policy question" —
`Permixion::argumentsCarryPolicySubject()`. A `Scope` does not count as a subject
(it is Permixion's own concern); a model, class-string or DTO does. Keep them
sharing it so they cannot drift apart.

## Public API surface

| Entry point | What it is |
|---|---|
| `Permixion` (`src/Permixion.php`) | The engine. Container singleton, aliased `permixion`; every other surface calls into it via `app('permixion')`. |
| `Facades\Permixion` | Static face of the above — `createRole`, `findRole(OrFail)`, `createPermission`, `getAllRoles`, `deleteRole`, `clearCache`, … |
| `Traits\HasRoles` | Mixed into the consumer's user model. `assignRole` / `removeRole` / `syncRoles`, `hasRole` / `hasAnyRole` / `hasAllRoles` / `hasExactRoles` / `hasRoleAnywhere`, `hasPermissionTo` / `hasAnyPermission` / `hasAllPermissions`, `givePermissionTo` / `revokePermissionTo`, `getRoles` / `getRoleNames` / `getAssignedRoleNames` / `getDirectPermissions` / `getAllPermissions`, and the `can()` override. Role questions answer *in force* (survives its throughs whole), never merely assigned; `getAssignedRoleNames` is the raw stored list. |
| `Contracts\HasPermissions` | Opt-in marker + interface for the `Gate::before` hook. Mirrors the trait's signatures. |
| `Models\Role` / `Models\Permission` | **Plain PHP wrappers around a Taxon `Tag`** — see gotchas. |
| `GlobalScope` | Singleton sentinel meaning "the unscoped assignment", as opposed to `null` = "resolve ambient scope". |
| `helpers.php` | Global `role(string): ?Role` and `permission(string): ?Permission`. |
| Blade | `@role` / `@hasrole` / `@hasanyrole` / `@hasallroles` / `@unlessrole` / `@permission` / `@haspermission`, each optionally taking a scope. |
| Middleware aliases | `role:a|b`, `permission:x|y`, `role_or_permission:a|x` — registered in the provider; refusals throw `UnauthorizedException` (403) carrying `getRequiredRoles()` / `getRequiredPermissions()` for the consumer to render. |
| Artisan | `permixion:create-role`, `permixion:create-permission`, `permixion:show-permissions [--role=]` — console only. |

## Domain model — Taxon's tables, plus one

Roles and permissions live in Taxon's two tables, `tags` and `taggables` (names
configurable via `taxon.tables.*`). Permixion owns one migration,
`permixion_assignment_throughs` (since 0.6.0), loaded unless published:

- Two root category tags, `roles` and `permissions` (slugs configurable via
  `permixion.categories.*`), created lazily by `rolesCategory()` /
  `permissionsCategory()` with `assignable => false`.
- A **role** or **permission** is a `Tag` whose `parent_id` is the matching
  category.
- **Role → permission** is a tag-tagging-a-tag row in `taggables` (`Tag` itself
  uses Taxon's `HasTags`).
- **User → role/permission** is a `taggables` row against the user model, with
  `scope_type` / `scope_id` carrying the scope (both `NULL` = global).
- **Acting for** — an assignment's throughs are rows in
  `permixion_assignment_throughs` keyed by that `taggables.id`: a holder morph or
  a ceiling role id. No foreign keys (Taxon's published migrations can sort after
  it); `deleteUserTagPivot()` deletes them with the assignment. Every check goes
  through `Permixion::effectivePermissionsFor()` — the union over assignments of
  role ∩ every through, recursive, cycle-safe, depth-limited, failing closed. The
  decisions and the ledger are in `docs/spec/acting-for.md`.

**Identity is `Tag.name`, verbatim — never the slug.** The whole trait
deliberately bypasses Taxon's `addTag`/`hasTagIn` helpers because those run input
through `Str::slug`, which would mangle `posts.create` into `postscreate` and
collapse distinct permissions together. Any new lookup path must match on `name`.

The `slug` column is written **verbatim from the name** for the same reason
[T: src/Permixion.php `createRole()` / `createPermission()`]. Taxon 0.4.0's
`harden_tags_unique_index_against_nulls` migration made
`tags_unique_slug_parent_tenant` enforce uniqueness over `COALESCE`'d nulls, so two
sibling tags may no longer share a slug — and a slugged copy is lossy, so
`account.view` and `accountview` collided on insert. Verbatim is the only mapping
injective on the name, and it leaves the index enforcing precisely the identity
above. Pinned by `NameIdentityTest`.

When that index refuses the write anyway — a row from an earlier version holding
the slugged value a new name needs — `createRole()` / `createPermission()` catch
it and rethrow `RoleSlugCollision` / `PermissionSlugCollision`, naming the item
refused and the sibling holding the slug, with the database error as the cause
[T: src/Exceptions/RoleSlugCollision.php]. Pinned by `SlugCollisionTest`. A
same-name create is caught before the insert and still throws
`RoleAlreadyExists` / `PermissionAlreadyExists`.

Other concepts worth knowing:

- **Scope** (`RobinsonRyan\Taxon\Contracts\Scope`) — resolved from route /
  session / callback per `permixion.teams.resolver`, and **only** when
  `teams.enabled` is true. `resolveCurrentScope()` reads ambient request state; it
  never reads the scope of the thing being authorized. The `Gate::before` hook
  and `can()` prefer a `Scope` passed in the arguments and fall back to it.
- **`teams.global_fallback`** — off by default. On, a scoped **read** also matches
  unscoped rows (spatie teams semantics). Writes always target the exact scope.
- **Wildcards** — `wildcards.enabled` (default on): a stored `posts.*` matches any
  `posts.`-prefixed ability. Prefix matching only, one delimiter form.
- **`permixion.strict`** (default `true`) — throws `RoleAlreadyExists` /
  `PermissionDoesNotExist` etc. on writes. Lenient mode auto-creates instead.
  Reads never throw in either mode.
- **Cache** — role→permission name lists only, keyed `permixion.role_permissions.<role>`.
  Every mutating path calls `clearCache()`; a new one must too. Effective
  permissions are memoised per request in the `scoped` `permixion.effective-memo`
  binding; `clearCache()` and every assignment write flush it.

## Gotchas a newcomer will otherwise hit

1. **`Models\Role` and `Models\Permission` are not Eloquent models.** Despite the
   namespace they are plain readonly-ish wrappers holding a `Tag`. There is no
   `Role::find()`, no query builder, no relations. Reach the real row with
   `$role->tag()`; find them through the `Permixion` engine/facade.
2. **The consumer's user model needs Taxon's `HasTags` as well as `HasRoles`.**
   Most engine methods guard with `method_exists($user, 'tags')` and **silently
   return `false` or no-op** without it — an assignment that appears to succeed and
   does nothing. Other paths (`getRoles`, `getDirectPermissions`) call
   `$this->tags()` directly and fatal. Both failure modes trace back to the same
   missing trait.
3. **`null` scope means "ambient", not "global".** With a resolver configured,
   passing `null` resolves the current scope. To target the unscoped row
   explicitly, pass `GlobalScope::instance()`.
4. **`composer.lock` is gitignored here** (library, not application). Refreshing it
   is local verification; it is never part of a commit.
5. **`robinsonryan/taxon` is consumed by tag from GitHub** (a `vcs` repository),
   the same way every application consumes this package. A taxon change reaches
   this suite only after taxon tags it and `ddev composer update robinsonryan/taxon`
   runs here — never through a local path mount. `tests/Feature/TaxonVersionTest.php`
   fails the gate if the installed taxon sits outside the `require` constraint. If
   you temporarily point Composer at a local checkout to develop both packages,
   pin it with `options.versions` and the second assertion in that file checks the
   pin against the checkout's changelog; remove the path entry before committing.
6. **PHPStan (level 8) analyses `src` and `tests/Fixtures` only** — not the test
   files. A type error inside a `.php` test is caught by nothing.
7. **The `parentCan()` guard in `HasRoles` looks redundant to the analyser and is
   not.** `parent` is whatever class the consumer mixes the trait into; PHPStan
   only ever sees the single test host, hence the `@phpstan-ignore`. Removing the
   guard fatals for any host with no parent `can()`.

## The gate

`ddev composer quality` — `lint:check` → `analyze` → `refactor:check` → `test`
(Pint, PHPStan, Rector, Pest). Verify-only: it never rewrites files. Fix with
`ddev composer lint` / `ddev composer refactor` and re-stage.

`.githooks/pre-commit` runs **the whole gate, tests included** — packages are
small enough (~15 s measured here) that the apps' exclude-the-tests compromise
does not apply. It is path-aware, so a docs-only commit skips it. Never bypass
with `--no-verify`; `PACKAGE_SKIP_GATE=1` is a human emergency valve and **agents
must never set it**.

That hook file is a **copy** of the harness's canonical one. Do not edit it here
— edit `$CLAUDE_HARNESS_DIR/core/stacks/laravel-package/hooks/pre-commit` and
re-run that directory's `install.sh`.

`harness package-check` sweeps every first-party package: the gate, a
`--prefer-lowest` run proving the declared version floor really resolves,
outdated and vulnerability scans, and in-constraint updates behind a re-run of
the gate. It never tags a release. Run it before any app re-resolves its
packages.

Full definition: `imports/package-quality-gate.md`. Skill: `/package-quality`.

## Testing

Pest 4 + Orchestra Testbench against **PostgreSQL** (DDEV's `db`) — see
`tests/TestCase.php`. It uses `RefreshDatabase`, forces `taxon.id_type =>
incrementing` and `permixion.strict => true`, and registers Taxon's migrations
(from `vendor/`) and the fixture migrations (`tests/Fixtures/database/migrations`)
as migrator paths. The database is `testing`, or `testing_wt_<slug>` for a checkout
nested at `.claude/worktrees/<slug>/`, created on first run;
`PERMIXION_TEST_DB_*` override it. Drop a worktree's database at teardown
(`ddev exec "dropdb -h db -U db testing_wt_<slug>"`).

**`tests/Postgres/`** additionally builds `tags`/`taggables` in Taxon's `uuid7`
mode on a connection of its own, in `<suite database>_uuid7`, rebuilding that
schema per test (`PGDATABASE_TESTING` overrides the name; it refuses to run in the
development `db`).

```bash
ddev composer test
ddev exec vendor/bin/pest --filter=GateBeforeTest
```

There is no `ddev artisan` and no `ddev pest` here — those are app commands.

Coverage worth knowing about before you change behaviour: `GateBeforeTest` and
`CanMethodTest` pin both short-circuits in **both** config states, including the
no-deny and no-throw guarantees; `NameIdentityTest` pins name-not-slug identity;
`GlobalScopeAndFallbackTest` pins scope semantics. These are characterization
tests — if one goes red you have changed an authorization decision, not a detail.
`ActingForTest` pins acting-for, each grant beside its refusal (cycles, depth,
inactive and deleted throughs, capped super admin, `withCeiling`);
`PennywiseParityTest` checks every combination of Pennywise's rights rules against
a port of its code.

`SeederAcceptanceTest` is the release bar for a Taxon upgrade: it drives role and
permission seeding through `db:seed` with application-shaped seeder classes,
including a `createQuietly()` user and a whole run inside `Model::withoutEvents()`
— the paths Taxon 0.5.0's deferred tag assignment lost a grant on.

## Releases

**Tag it yourself when it is releasable.** Cut the tag once the full gate is green
on the merge commit, `CHANGELOG.md` names anything breaking in plain language, and
the version follows caret-on-zero semver — on `0.x` a change that could break a
consumer takes the minor, a fix takes the patch. Annotate it and push it with the
merge. Stop and ask only when the gate is red, review findings are open, or the
version choice is genuinely contested. Rule and reasoning: the laravel-package
constitution §4. (Ruled 2026-08-18, replacing "Ryan cuts every tag".)

The package sits on `0.x` on purpose: under Composer `^0.4.0` resolves to
`>=0.4.0 <0.5.0`, so **every minor may break**, which is the signal wanted while
the API settles. It goes `1.0.0` when the consuming apps ship publicly.

Behavior changes land in `CHANGELOG.md` in the commit that makes them.

## Reference package

`~/dev/php/packages/robinsonryan/hey-you/` is the reference implementation —
service provider shape, Testbench setup, tool configs, table prefixing. Read it
before inventing a variant.

## Quick reference

- **DDEV**: `ddev start`, `ddev ssh`
- **Gate**: `ddev composer quality`
- **Tests**: `ddev composer test`
- **Style fix**: `ddev composer lint`
- **Rector fix**: `ddev composer refactor`
