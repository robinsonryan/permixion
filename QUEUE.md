# Implementation Queue

> Deferred work, captured mid-session, picked up deliberately. Managed by `/queue`;
> convention: `$CLAUDE_HARNESS_DIR/notes/implementation-queue.md`. Hand-editing is fine.

## Queued
### Examine the `Gate::before` short-circuit
- **Added**: 2026-08-07 · Track P / P4 gating
- **Tier**: needs a decision before any code change
- **Why deferred**: every option changes an authorization outcome for a live consumer (ccstake runs with `register_gate => true`)
- **Context**: `docs/gate-before-review.md`. The hook is model-blind — it takes `($user, $ability)` and never sees Laravel's `$arguments`, so `can('update', $postA)` and `can('update', $postB)` are indistinguishable and any policy that discriminates by instance is bypassed. Also: scope comes from ambient request state rather than the model, super-admin grants every ability unscoped, and neither the hook nor `can()` has a single test. Confirmed safe: the `?true` return type makes a hard-deny structurally impossible, and the read path has no strict-mode throw.


### Support Pest 5 / PHPUnit 13 / PHP 8.4+ in the constraint matrix
- **Added**: 2026-08-07 · harness health & efficiency session — apps are queued to upgrade to Pest 5 for Tia; consuming apps can't move until this package allows it
- **Tier**: SOLO
- **Why deferred**: harness-wide decision made first; per-package constraint widening is independent work
- **Context**: current: php ^8.2 (lowered from ^8.3 by the quality-baseline pass, harness
  decision D-C), pest ^4.0. Widen composer constraints to include pest ^5 / phpunit ^13 /
  php 8.4+ and run the suite on the new matrix. Research + decisions:
  $CLAUDE_HARNESS_DIR/notes/harness-health-research-2026-08.md. ddev already runs php 8.5
  against the ^8.2 constraint. Consumed by ccstake

### Untested authorization paths (RBAC coverage gap)
- **Added**: 2026-08-07 · package quality baseline (Track P / P4)
- **Tier**: LIGHT
- **Why deferred**: the baseline pass gates existing tests; writing an RBAC suite is its own scoped work
- **Context**: 33 tests / 81 assertions cover role assignment, team scoping, wildcards,
  name identity and global fallback. Authorization surfaces with **no test at all**:
  - `HasRoles::can()` — the Gate-compatibility alias. Nothing exercises it, which is how the
    two crash bugs fixed in this pass survived. Wants cases for: string ability, `Permission`
    ability, a `Scope` passed via `$arguments[0]`, a non-array `$arguments` (e.g. a model or
    class-string), and an iterable/enum ability delegating to the parent.
  - `PermixionServiceProvider::registerGatePermissions()` — the `Gate::before` hook that
    grants super-admin and scope-resolved permissions app-wide. No test asserts that it
    returns `null` (rather than `false`) on a miss; returning `false` there would hard-deny
    every other gate in a consuming app.
  - The three middlewares (`RoleMiddleware`, `PermissionMiddleware`,
    `RoleOrPermissionMiddleware`) — no test covers the deny path or the 403 they raise.
  - `hasExactRoles()` — the only `HasRoles` check method with no test.
  - The five exception classes (`RoleDoesNotExist`, `PermissionDoesNotExist`,
    `RoleAlreadyExists`, `PermissionAlreadyExists`, `UnauthorizedException`) — no test
    names any of them, so nothing pins which paths throw vs. return false. `TestCase`
    pins `permixion.strict` to `true` for every test, so the `false` branch (return
    instead of throw) has never executed.

## Blocked

## Archive
