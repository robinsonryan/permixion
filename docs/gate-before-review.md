# `Gate::before` — open review

> **Status: for examination, not a bug report.** Raised 2026-08-07 while gating
> permixion to the harness quality baseline (Track P / P4). Nothing here is known
> to be broken in production. The point is that this hook makes a very large
> authorization promise that **no test currently exercises**, and its behaviour
> interacts with consuming apps in ways worth deciding deliberately.
>
> Live in ccstake today: `config/permixion.php` sets `register_gate => true`.

## What it does

`PermixionServiceProvider::registerGatePermissions()` installs:

```php
Gate::before(function ($user, string $ability): ?true {
    if (! $user instanceof HasPermissions) {
        return null;
    }

    if (config('permixion.super_admin.enabled')) {
        if ($user->hasRole(config('permixion.super_admin.role'))) {
            return true;
        }
    }

    $scope = app(Permixion::class)->resolveCurrentScope();

    if ($user->hasPermissionTo($ability, $scope)) {
        return true;
    }

    return null; // Let other gates handle it
});
```

Laravel runs every `Gate::before` callback **ahead of any policy**. A non-null
return short-circuits: the policy never executes.

## What is already safe

Two things worth stating so review effort goes to the parts that need it.

1. **It cannot deny.** The return type is `?true`, so the only reachable values
   are `true` and `null`. It is structurally incapable of returning `false`, and
   PHP would raise a `TypeError` if a future edit tried. A `Gate::before` that
   returned `false` would hard-deny every other gate in the consuming app; that
   failure mode is closed by the signature.
2. **The read path does not throw.** `hasPermissionTo()` → `userHasPermission()`
   returns `bool` and has no strict-mode throw. The `permixion.strict` throws are
   confined to the *write* paths (`attachRoleToUser`, `attachPermissionToUser`).
   So an ability permixion has never heard of yields `null` and falls through,
   rather than exploding inside an unrelated gate check.

## What to examine

### 1. The hook is model-blind — this is the big one

The closure takes `($user, string $ability)`. Laravel passes a third argument,
`$arguments`, carrying the model being authorized. **It is not accepted and not
consulted.**

Consequence: `$user->can('update', $postA)` and `$user->can('update', $postB)`
are indistinguishable. If the user holds an `update` permission *anywhere in the
resolved scope*, `Gate::before` returns `true` and `PostPolicy::update()` never
runs — including the `$post->author_id === $user->id` clause that policy exists
to enforce.

Any policy that discriminates between instances of the same model is bypassed for
users holding the matching permission name. Whether that is correct depends
entirely on whether permissions are intended to be coarse capabilities
("this user may update posts at all") or fine-grained grants. Today the code
implements the former while a consuming app's policies may assume the latter.

**This is directly in tension with ccstake's authorization constitution**, which
states policies are the single source of truth and every endpoint authorizes
independently. With `register_gate => true`, they are not the single source of
truth — permixion outranks them.

### 2. Scope comes from ambient state, not from the model

`resolveCurrentScope()` reads the *current request/session* scope, not the scope
of the thing being authorized. A user with the permission in scope A, acting on a
resource belonging to scope B, is granted if ambient resolution says A. Because
of (1) the hook cannot see the resource, so it cannot possibly cross-check.

Worth confirming against how ccstake resolves organization scope per request.

### 3. Super admin grants *every* ability

The super-admin branch returns `true` for any ability string whatsoever,
including abilities no policy defines and abilities belonging to other packages'
gates. That is probably the intent of "super admin", but it means the role is
un-scopeable and un-revokable per-feature — worth an explicit decision rather
than an inherited default.

Note the branch calls `$user->hasRole($superAdminRole)` with **no scope
argument**, so it checks the role globally.

### 4. Wildcards broaden the short-circuit

With `permixion.wildcards.enabled`, `permissionMatches()` treats a stored
`posts.*` as matching any `posts.`-prefixed ability. Combined with (1), one
wildcard grant bypasses every policy for that prefix. The matching itself looks
correct; the point is the blast radius when it is combined with a model-blind
short-circuit.

## Test coverage — the actual reason this doc exists

Verified 2026-08-07 against the 33-test suite:

| Surface | Covered? |
|---|---|
| `Gate::before` hook | **No test at all** |
| `HasRoles::can()` | **No test at all** (two latent fatals were found here by static analysis, not by tests) |
| All three middlewares | No tests |
| `hasExactRoles()` | No test |
| `permixion.strict` false branch | Never executed — `TestCase` pins `strict` to `true` |

The suite is green and always has been. It is green about things other than the
package's central promise.

## Questions to answer before changing anything

1. Are permissions meant to be **coarse capabilities** (current behaviour) or
   **fine-grained grants** that policies then refine? The answer decides whether
   (1) is a bug or the design.
2. Should the hook accept `$arguments` and decline (`return null`) when a model
   is present, letting the policy decide? That would make permixion additive to
   policies rather than superior to them — a significant behaviour change for
   every consumer, and the safest-sounding option is not obviously the right one
   (it would stop granting things it grants today).
3. Should `register_gate` default to `false`, making the short-circuit opt-in?
4. Should super-admin be scope-aware?

## Do not

Do not "fix" any of this by changing what is granted, until 1–4 are answered.
Every option above changes an authorization outcome for a live consumer. Write
the tests first — the coverage table is the actionable part of this document.
