# `Gate::before` — review, resolved in 4.0.0

> **Status: closed.** Opened 2026-08-07 while gating permixion to the harness
> quality baseline (Track P / P4); investigated and resolved 2026-08-08. The
> mechanism it described was real. Its assessment of the *exposure* was wrong in
> three places, corrected below — the corrections are kept rather than deleted,
> because each one was load-bearing in deciding what to actually do.

## Outcome

| Concern | Verdict |
|---|---|
| `Gate::before` is model-blind | **Not a permixion defect** — spatie behaves identically. Now opt-out via `gate.defer_to_policies`. |
| `HasRoles::can()` is model-blind | **A genuine permixion defect.** Spatie has no `can()` override. Narrowed unconditionally in 4.0.0. |
| Scope comes from ambient state | Confirmed, unchanged, now pinned by a test. |
| Super admin grants every ability | Confirmed, deliberate, left alone. |
| Wildcards broaden the short-circuit | Confirmed; blast radius now bounded by the above. |
| No test coverage | Closed. 33 → 88 tests. |

## What it does

`PermixionServiceProvider::registerGatePermissions()` installs a `Gate::before`
hook. Laravel runs those **ahead of any policy**; a non-null return short-circuits,
and the policy never executes.

## Correction 1 — the hook requires an interface, and almost nobody declared it

The hook's first line is:

```php
if (! $user instanceof HasPermissions) {
    return null;
}
```

That is an **interface** check, but the behaviour comes from the `HasRoles`
**trait** — and a trait cannot satisfy an interface for you. The README documented
usage *without* the interface, so anyone following it got `register_gate => true`
and a hook that silently did nothing.

Measured across all four consumers at the time of review:

| App | `implements HasPermissions` | `register_gate` | Hook status |
|---|---|---|---|
| ccstake | No | `true` | **Inert** |
| nbss | Yes | `false` | Inert — never installed |
| afwd | No | `true` | Inert |
| larquacious | No | `true` | Inert |

**The hook had never executed in production, nor in a single test** — the package's
own `User` fixture had the same gap, which is exactly why 33 green tests said
nothing about it.

**Decision: keep the interface guard.** Requiring an explicit
`implements HasPermissions` before a package may outrank your policies is better
design than spatie's capability check, and it is now documented in the README and
at the config toggle. Two fixtures (`User`, `GateUser`) pin both sides so the guard
cannot be "tidied" into a `method_exists` check without a failing test — that
one-line tidy-up would have switched the short-circuit on in three live apps.

## Correction 2 — model-blindness is spatie's behaviour, not permixion's defect

The original review framed the model-blind hook as permixion's headline defect. It
isn't. `spatie/laravel-permission` v6 does the same thing:

```php
// vendor/spatie/laravel-permission/src/PermissionRegistrar.php:125
$gate->before(function (Authorizable $user, string $ability, array &$args = []) {
    if (is_string($args[0] ?? null) && ! class_exists($args[0])) {
        $guard = array_shift($args);
    }
    if (method_exists($user, 'checkPermissionTo')) {
        return $user->checkPermissionTo($ability, $guard ?? null) ?: null;
    }
});
```

It receives `$args` but uses them only to shift off a guard string; the model is
never consulted. Its config flag `register_permission_check_method => true` is
permixion's `register_gate => true`. Identical shape, identical default.

So this was **inherited by design** from the package permixion mirrors. It is now
opt-out via `gate.defer_to_policies`, defaulting to `false` so parity holds unless
a consumer deliberately diverges.

The genuine permixion-specific defect was **`HasRoles::can()`** — spatie has no
`can()` override anywhere in `src/`. That door had no interface guard, ignored
`register_gate`, and applied wherever the trait was used. It was narrowed
unconditionally in 4.0.0.

## Correction 3 — the super-admin check is not global

The original review claimed the super-admin branch calls `hasRole()` with no scope
argument and therefore "checks the role globally." **It does not.** `hasRole()`
opens with:

```php
$scope ??= app('permixion')->resolveCurrentScope();   // HasRoles.php:75
```

Omitting the scope means *ambient* scope, not global. Moot while `teams.enabled`
is `false`, but the claim as written would have misled anyone acting on it.

Worth noting alongside: the branch is largely redundant, because
`hasPermissionTo()` checks super admin again at `HasRoles.php:180` with the same
resolved scope.

## What was already safe, and still is

1. **It cannot deny.** The return type is `?true`, so `false` is unreachable and a
   future edit attempting it raises a `TypeError`. A `Gate::before` returning
   `false` would hard-deny every other gate in the consuming app; the signature
   forecloses it. Pinned by test.
2. **The read path does not throw.** `hasPermissionTo()` → `userHasPermission()`
   returns `bool`. The `permixion.strict` throws are confined to the *write* paths,
   so an unknown ability falls through rather than exploding inside an unrelated
   gate. Pinned by test, in both strict modes.

## Still true, and deliberately unchanged

**Scope comes from ambient state, not from the subject.** `resolveCurrentScope()`
reads the current request/session scope, never the scope of the thing being
authorized — and it does not read a `Scope` passed in `$arguments` either. A
permission granted only in team A is invisible to a check that passes team A as an
argument while ambient resolution yields nothing. Surprising enough that it now has
its own test rather than a doc sentence.

**Super admin grants every ability**, including abilities no policy defines and
abilities belonging to other packages' gates. That is the point of super admin. It
is *not* narrowed by `defer_to_policies` — narrowing it would require every policy
in every consumer to grow its own `before()` method.

**Wildcards broaden the short-circuit.** With `wildcards.enabled`, a stored
`posts.*` matches any `posts.`-prefixed ability. The matching is correct; the
concern was blast radius when combined with model-blindness, which
`defer_to_policies` now bounds.

## Why this was latent rather than live

Beyond the interface guard, ccstake could not have been exploited anyway:
`TaxonCategoriesSeeder` creates a `roles` category and **no permissions category**,
and there are zero calls to `hasPermissionTo`, `givePermissionTo`,
`createPermission` or the `permission:` middleware anywhere in `app/`, `routes/` or
`resources/`. With no permissions in existence, the permission branch could never
fire.

What would have armed it: seeding a permission whose name matches a policy ability.
ccstake's policies use bare Laravel verbs (`view`, `update`, `delete`, `create`), so
the collision would have been immediate and total — 20 policies' worth of ward
boundaries, gone in one deploy. That is why this was worth fixing while it cost
nothing, and why the naming rule below matters more than any of the code.

## The rule that prevents recurrence

**Never name a permission after a Laravel ability verb.** Namespace them —
`registrations.update`, not `update` — and a permission cannot collide with a policy
ability in the first place. This is cheaper and more durable than any of the
machinery above, and it is now recorded in consuming apps' `CLAUDE.md`.

## Coverage, closed

| Surface | Before | After |
|---|---|---|
| `Gate::before` hook | No test at all | Both guard paths, no-deny, no-throw, super admin, both flag states |
| `HasRoles::can()` | No test at all | Bare / scoped / subject-bearing forms, both crash-safety fixes |
| All three middlewares | No tests | Admit, refuse, guest, exception payload |
| `hasExactRoles()` | No test | Order, subset, superset, empty, scope isolation |
| `permixion.strict` false branch | Never executed | Both branches, including lenient auto-create |
