# Implementation Queue

> Deferred work, captured mid-session, picked up deliberately. Managed by `/queue`;
> convention: `$CLAUDE_HARNESS_DIR/notes/implementation-queue.md`. Hand-editing is fine.

## Queued
### Namespace the `parentCan()` trait helper
- **Added**: 2026-08-08 · self-review of the 0.4.0 can() narrowing
- **Tier**: SOLO
- **Why deferred**: theoretical, and fixing it mid-release would mean re-tagging and
  re-updating four apps for no live defect
- **Context**: `HasRoles::can()` was split and now leans on a new
  `protected function parentCan()` (src/Traits/HasRoles.php:263). A trait's methods land
  in the host class's namespace, so any consuming model that defines its own
  `parentCan()` fatals. Verified 2026-08-08 that none of afwd/ccstake/larquacious/nbss
  does. Rename to `permixionParentCan()` (matching how `argumentsCarryPolicySubject()`
  was deliberately put on the `Permixion` service rather than the trait) and ship with
  the next change that touches the package.

### A bare `Scope` argument never actually scopes `can()`
- **Added**: 2026-08-08 · self-review of the 0.4.0 can() narrowing
- **Tier**: needs a decision — it changes an authorization outcome
- **Why deferred**: pre-dates 0.4.0 and is unchanged by it; fixing it would alter what
  is granted, which is not a lint fix
- **Context**: `can('posts.create', [$team])` scopes correctly — the array form is read
  at `$arguments[0]`. `can('posts.create', $team)` does **not**: the bare form falls
  through scope extraction and checks with a null scope, so it can grant on an unscoped
  row that the scoped form would refuse. Laravel treats `$ability, $arg` and
  `$ability, [$arg]` as equivalent, so the asymmetry is surprising. `argumentsCarryPolicySubject()`
  already classifies a bare Scope correctly (not a policy subject); only the extraction
  block below it is array-shaped. Fix is small — but decide first whether the bare form
  should scope (narrower, may remove grants) or stay unscoped (status quo).

### Support Pest 5 / PHPUnit 13 / PHP 8.4+ in the constraint matrix
- **Added**: 2026-08-07 · harness health & efficiency session — apps are queued to upgrade to Pest 5 for Tia; consuming apps can't move until this package allows it
- **Tier**: SOLO
- **Why deferred**: harness-wide decision made first; per-package constraint widening is independent work
- **Context**: current: php ^8.2 (lowered from ^8.3 by the quality-baseline pass, harness
  decision D-C), pest ^4.0. Widen composer constraints to include pest ^5 / phpunit ^13 /
  php 8.4+ and run the suite on the new matrix. Research + decisions:
  $CLAUDE_HARNESS_DIR/notes/harness-health-research-2026-08.md. ddev already runs php 8.5
  against the ^8.2 constraint. Consumed by ccstake

## Blocked

## Archive

### ~~Examine the `Gate::before` short-circuit~~ — resolved 2026-08-08 in 4.0.0
Investigated, corrected, and closed. The mechanism was real but the exposure
assessment was wrong: the hook requires `implements HasPermissions`, which only
nbss declared, so it had never executed in any consumer or any test. Model-blindness
turned out to be spatie's own behaviour (`PermissionRegistrar.php:125`), inherited by
design; the genuine permixion defect was `HasRoles::can()`, which spatie does not
override at all. `can()` narrowed unconditionally; the hook made opt-out via
`gate.defer_to_policies`. Full write-up, including the three corrections, in
`docs/gate-before-review.md`.

### ~~Untested authorization paths (RBAC coverage gap)~~ — resolved 2026-08-08
33 → 88 tests. `Gate::before` (both guard paths, no-deny, no-throw, super admin,
both `defer_to_policies` states), `can()` (bare / scoped / subject-bearing, plus both
crash-safety fixes), all three middlewares (admit, refuse, guest, exception payload),
`hasExactRoles()` (order, subset, superset, empty, scope isolation), and both
`permixion.strict` branches including the lenient auto-create.

One item from the original entry is **deliberately not covered**: the five exception
classes are exercised through the paths that raise them rather than having tests of
their own. `UnauthorizedException::getRequiredPermissions()` is asserted directly;
`missingTrait()` has no test, since reaching it requires a user model that satisfies
the middleware's `method_exists` guard while lacking the trait.
