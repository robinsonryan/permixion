# Permixion

Role and permission management for Laravel with a spatie-shaped API, storing
everything as tags in `robinsonryan/taxon` rather than in tables of its own.
Roles and permissions are first-class scoped assignments, so the same user can
hold different roles per team/tenant.

Composer name: `robinsonryan/permixion` — a **library**, not an application.

## Conventions

@import ./constitution.md
@import ./imports/package-conventions.md
@import ./imports/package-quality-gate.md
@import ./imports/testing-conventions.md
@import ./imports/php-conventions.md
@import ./imports/git-conventions.md

> Linking the `laravel-package` stack also drops the inherited Laravel app
> conventions into `.claude/imports/` — `authorization-conventions.md`,
> `frontend-conventions.md`, `pwa-conventions.md`, `ddev-worktrees.md`. They are
> **deliberately not imported above**: they describe Inertia `can` maps, app-shaped
> Vite wiring, and nested app worktrees, none of which exist in a package. Read
> them if a question genuinely calls for one; do not load them by default.
>
> Permixion has **no frontend half** — there is no `resources/`, no build step, no
> `package.json`. `frontend-conventions.md` stays out.

> `.claude/` is a set of **harness symlinks** and is gitignored — a fresh clone has
> none of them and the `@import`s above resolve to nothing. If a convention file is
> missing, restore the link rather than guessing:
> `~/workspace/harness/link.sh project laravel-package $(pwd)`

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
2. Super admin — if `super_admin.enabled` and the user holds `super_admin.role`,
   return `true`. Unconditional god-mode, deliberately **not** narrowed by
   `defer_to_policies`.
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
`hasPermissionTo()` and falls back to the parent.

Both doors share one definition of "is this a policy question" —
`Permixion::argumentsCarryPolicySubject()`. A `Scope` does not count as a subject
(it is Permixion's own concern); a model, class-string or DTO does. Keep them
sharing it so they cannot drift apart.

## Public API surface

| Entry point | What it is |
|---|---|
| `Permixion` (`src/Permixion.php`) | The engine. Container singleton, aliased `permixion`; every other surface calls into it via `app('permixion')`. |
| `Facades\Permixion` | Static face of the above — `createRole`, `findRole(OrFail)`, `createPermission`, `getAllRoles`, `deleteRole`, `clearCache`, … |
| `Traits\HasRoles` | Mixed into the consumer's user model. `assignRole` / `removeRole` / `syncRoles`, `hasRole` / `hasAnyRole` / `hasAllRoles` / `hasExactRoles` / `hasRoleAnywhere`, `hasPermissionTo` / `hasAnyPermission` / `hasAllPermissions`, `givePermissionTo` / `revokePermissionTo`, `getRoles` / `getRoleNames` / `getDirectPermissions` / `getAllPermissions`, and the `can()` override. |
| `Contracts\HasPermissions` | Opt-in marker + interface for the `Gate::before` hook. Mirrors the trait's signatures. |
| `Models\Role` / `Models\Permission` | **Plain PHP wrappers around a Taxon `Tag`** — see gotchas. |
| `GlobalScope` | Singleton sentinel meaning "the unscoped assignment", as opposed to `null` = "resolve ambient scope". |
| `helpers.php` | Global `role(string): ?Role` and `permission(string): ?Permission`. |
| Blade | `@role` / `@hasrole` / `@hasanyrole` / `@hasallroles` / `@unlessrole` / `@permission` / `@haspermission`, each optionally taking a scope. |
| Middleware aliases | `role:a|b`, `permission:x|y`, `role_or_permission:a|x` — registered in the provider; refusals throw `UnauthorizedException` (403) carrying `getRequiredRoles()` / `getRequiredPermissions()` for the consumer to render. |
| Artisan | `permixion:create-role`, `permixion:create-permission`, `permixion:show-permissions [--role=]` — console only. |

## Domain model — no tables of its own

Permixion owns **zero migrations**. Everything lives in Taxon's two tables,
`tags` and `taggables` (names configurable via `taxon.tables.*`):

- Two root category tags, `roles` and `permissions` (slugs configurable via
  `permixion.categories.*`), created lazily by `rolesCategory()` /
  `permissionsCategory()` with `assignable => false`.
- A **role** or **permission** is a `Tag` whose `parent_id` is the matching
  category.
- **Role → permission** is a tag-tagging-a-tag row in `taggables` (`Tag` itself
  uses Taxon's `HasTags`).
- **User → role/permission** is a `taggables` row against the user model, with
  `scope_type` / `scope_id` carrying the scope (both `NULL` = global).

**Identity is `Tag.name`, verbatim — never the slug.** The whole trait
deliberately bypasses Taxon's `addTag`/`hasTagIn` helpers because those run input
through `Str::slug`, which would mangle `posts.create` into `postscreate` and
collapse distinct permissions together. Any new lookup path must match on `name`.

Other concepts worth knowing:

- **Scope** (`RobinsonRyan\Taxon\Contracts\Scope`) — resolved from route /
  session / callback per `permixion.teams.resolver`, and **only** when
  `teams.enabled` is true. `resolveCurrentScope()` reads ambient request state; it
  never reads the scope of the thing being authorized, and it does not read a
  `Scope` passed in gate `$arguments`.
- **`teams.global_fallback`** — off by default. On, a scoped **read** also matches
  unscoped rows (spatie teams semantics). Writes always target the exact scope.
- **Wildcards** — `wildcards.enabled` (default on): a stored `posts.*` matches any
  `posts.`-prefixed ability. Prefix matching only, one delimiter form.
- **`permixion.strict`** (default `true`) — throws `RoleAlreadyExists` /
  `PermissionDoesNotExist` etc. on writes. Lenient mode auto-creates instead.
  Reads never throw in either mode.
- **Cache** — role→permission name lists only, keyed `permixion.role_permissions.<role>`.
  Every mutating path calls `clearCache()`; a new one must too.

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
5. **`robinsonryan/taxon` is a path repository** (`../taxon`), mounted into the web
   container at `/var/www/taxon` by `.ddev/docker-compose.taxon.yaml`. Changes to
   taxon are live for permixion's suite without a `composer update`. Composer would
   guess that checkout's version from the checked-out **branch** (`dev-master`), which
   satisfies no real constraint, so the repository entry pins it with
   `options.versions => {"robinsonryan/taxon": "0.3.x-dev"}` — bump that by hand
   whenever the `require` constraint on taxon moves to a new minor, or the local
   install stops resolving.
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

Pest 4 + Orchestra Testbench against **SQLite in-memory** — see
`tests/TestCase.php`, which also forces `taxon.id_type => incrementing` and
`permixion.strict => true`, loads Taxon's migrations out of `vendor/`, and creates
the `users` / `teams` / `posts` fixture tables. The DDEV Postgres service exists
but the suite does not use it; if you add a test needing real Postgres behaviour,
change the connection deliberately rather than assuming it is already there.

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

## Releases

**Never tag.** Automation may update, gate, commit and push a branch, then report
"ready to tag" with a suggested version. Ryan cuts every tag. A version number is
a claim about behavior that a green gate cannot substantiate.

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
