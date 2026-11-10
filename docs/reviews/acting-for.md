# Review: acting-for (permixion, `8d37460`)

**Verdict: not solid — 9 findings (2 blocker, 2 major, 5 minor).** The permission path holds the invariant. It leaks on the role-question surfaces and on a scoped `can()` for contract-declaring users. One write path (`syncRoles`) lifts a cap.

## What holds, and how it was proved

- **One funnel.** Every permission answer goes through `Permixion::effectivePermissionsFor()`: `hasPermissionTo`, `hasAny/AllPermissions`, `can()`, the gate hook, the `permission` middleware, `@permission`, `userHasPermission`, `effectivePermissions()`. A grep of `src` found no other reader.
- **The intersection is exact.** 8,000 random pattern-set pairs × 24 names, wildcards on and off: zero cases where the intersection grants a name both sides do not.
- **Every fail-closed branch is pinned.** I broke the source 32 ways in a scratch copy and ran the 178 Feature tests each time; 28 breaks turned the suite red. That covers no cap at all, depth limit off or off by one, inactive throughs, deleted through holder, deleted ceiling role, wrong scope, capped super admin, `withCeiling`, bare scope ignored, and `syncRoles` dropping retained throughs.
- **The cycle rule is exact, not just safe.** Removing it changes no test, because with the depth limit on it is redundant for root results: any grant has a derivation that never revisits a holder.
- **Gates.** Suite: 191 passed, 373 assertions, exit 0 through `harness gate`. Pint, PHPStan and Rector: exit 0 on a pristine copy.

All probes and mutations ran in container-local copies (since deleted) against throwaway databases (since dropped). The worktree is unchanged apart from the findings file.

## Findings

### 1 — BLOCKER — role questions ignore caps and the inactive state
`src/Traits/HasRoles.php:100` (via `src/Permixion.php:648`); reached by `src/Middleware/RoleMiddleware.php:25`, `src/Middleware/RoleOrPermissionMiddleware.php:26`, `src/PermixionServiceProvider.php:100-112`, `src/Traits/HasRoles.php:399`. Scope: module.

- **Scenario, revoked holder.** A revoked `ApiKey` holding `accountant` has empty effective permissions, yet `hasRole('accountant')` is true and both `role:accountant` and `role_or_permission:accountant` admit it. An active key acting through a revoked app is admitted the same way.
- **Scenario, capped human.** A user holding `accountant` through a viewer grant has effective `[ledger.read]`. `role:accountant` returns 200, `@role('accountant')` renders, and `getAllPermissions()` lists all four ledger permissions. `permission:ledger.close` correctly returns 403.
- **Scenario, capped super admin.** An Agent holding `super-admin` through a viewer owner is refused by the Gate, but `hasRole('super-admin')` is true.
- **Judgement on the known item: yes, this is a leak for real consumers.** Pennywise reads the role in force directly: `/home/ryanrobinson/dev/services/pennywise/app/Access/AccessRules.php:45` (cannot give more access than you hold), `app/Proposals/OpenProposal.php:123` (owner-only), `app/Access/Actions/ListClients.php:45`. The natural port is `hasRole`, which would let a viewer-capped Agent hand out Accountant.
- **Docs.** `README.md:207` says the Blade directives decide from effective permissions; the `@role` family does not.
- **Direction.** Separate "assigned" from "in force". Inactive means no role in force. For capped, I suggest in force only when the role survives its throughs whole (super-admin only when unlimited). Derive `getAllPermissions()` from effective permissions; keep the raw list under another name for `syncRoles`.

### 2 — BLOCKER — a scoped `can()` is re-answered in the ambient scope
`src/Traits/HasRoles.php:323`, with `src/PermixionServiceProvider.php:147`. Scope: module.

- **Scenario.** The request is on Acme. A `GateUser` is an accountant on Acme and viewer-capped on Globex. `hasPermissionTo('ledger.close', $globex)` is false, but `can('ledger.close', $globex)`, `can('ledger.close', [$globex])` and `Gate::forUser()->allows('ledger.close', $globex)` are all true.
- **Why.** When the scoped check says no, `can()` hands the question to the parent, and the gate hook answers it in the ambient scope.
- **Literal invariant.** Bare and array forms gave identical answers in every probe, so "scopes exactly like the array form" holds — both leak.
- **Who is affected.** Models that declare `HasPermissions` and have a parent `can()`, i.e. any Authenticatable user. Trait-only models and the Agent fixture are safe on `can()`.
- **Tests.** `tests/Feature/CanMethodTest.php:84` only uses the trait-only fixture. `tests/Feature/GateBeforeTest.php:199` pins the cross-scope grant as expected behaviour.
- **Changelog.** `CHANGELOG.md:21-26` says the leak is closed.
- **Direction.** Have the hook use the scope named in the arguments when there is one (`scopeFromArguments()`), else ambient. This flips `GateBeforeTest.php:199` and `:210`, so it needs the owner's ruling. Until then the changelog should not claim closure for contract-declaring models.

### 3 — MAJOR — `syncRoles()` to a different role lifts the cap
`src/Traits/HasRoles.php:88`. Scope: module.

- **Scenario.** An Agent holds `accountant` through a viewer owner; effective `[ledger.read]`. After `syncRoles(['bookkeeper'], $team)` it has `[ledger.draft, ledger.post, ledger.read]`, with zero through rows left. Its owner still only reads.
- **Why it matters.** `syncRoles` is the usual "change role" call, takes no throughs, and `README.md:220` promises "Writes never widen".
- **Same root.** A second uncapped role or a direct permission also sits beside the cap. That part is documented and tested; `syncRoles` is the unthought case.
- **Direction.** Give `syncRoles` throughs, or carry over the throughs of what it replaces, or refuse on a capped holder. The larger option is a holder-level "acts for".

### 4 — MAJOR — two routes to one role collapse, silently
`src/Permixion.php:707`. Scope: module, needs a decision.

- **Scenario.** A bookkeeper member gains a Firm route to `bookkeeper` through a viewer grant and drops to `[ledger.read]`. Leaving the Firm (`removeRole`) leaves nothing, so the membership is gone too. A staffer of two firms (accountant grant and viewer grant) gets `[ledger.read]`. No call throws.
- **It fails closed in every order I tried.** This is a parity defect, not a leak.
- **Judgement on the queued fixes:**
  - *OR-ed routes, where a route-less call uncaps.* This gives up "writes never widen": `syncRoles` re-attaches retained roles with no through, and so does any idempotent seeder. It also needs "uncapped" as an explicit row and a route identity for removal.
  - *A separate holder per Firm route.* No package change, but the user's own `can()`, Gate and middleware never see the route, so Pennywise still writes the "higher of" by hand.
  - *A third option, available today:* route-specific role names (`firm-accountant` with the same permissions). It breaks only when two firms grant the same role.
- **Recommendation.** One consumer does not justify routes in storage yet. For now make the collapse loud: refuse an `assignRole` that would merge a capped route with an uncapped one.

### 5 — MINOR — assignment and throughs are not written atomically
`src/Permixion.php:711`. Scope: module.

- **Scenario.** With the through insert made to throw after the assignment row is written, `assignRole('accountant', $team, ['viewer'])` raises, yet one assignment row and zero through rows remain and `hasPermissionTo('ledger.close')` is true.
- **Direction.** One transaction around both writes. More broadly, an absent through row reads as "uncapped", so any loss of rows widens.

### 6 — MINOR — the memo outlives the unit of work outside requests and jobs
`src/PermixionServiceProvider.php:31`. Scope: module.

- **Scenario.** After another process revokes an app and deletes an Agent's role row, fresh models in this process still answer true for both. They answer false only after `forgetScopedInstances()`.
- **Why.** In the framework only the queue worker resets scoped instances (`QueueServiceProvider.php:267`). A daemon, a stdio tool server or a tenant loop keeps stale grants for its lifetime.
- **Direction.** Document a per-unit flush for long-lived processes, or bound the memo.

### 7 — MINOR — three behaviours no test pins
`tests/Feature/ActingForTest.php:113`. Scope: module.

- **Re-assign replaces throughs.** Making a re-assign replace the existing throughs (a widening write) passes all 178 tests, because the test uses nested roles where add and replace agree. The real code is right: viewer then bookkeeper stays `[ledger.read]`.
- **Flush after `givePermissionTo`.** Removing it passes.
- **Config in the memo key.** Removing it passes.
- **Direction.** Add the reverse-order case (narrow ceiling first, wider second).

### 8 — MINOR — `harness db-check` fails on the new migration
`database/migrations/2024_01_05_000000_create_permixion_assignment_throughs_table.php:39`. Scope: module.

- **Evidence.** One violation: bigint primary key from `$table->id()`.
- **Direction.** Only `assignment_id` and `role_id` need to follow taxon's key type; the table's own key can be UUID v7 in both modes. Otherwise record the exemption.

### 9 — MINOR — two engine methods are now dead
`src/Permixion.php:802` and `:819`. Scope: module.

- **Evidence.** `detachAllUserRoles()` and `userHasDirectPermission()` each had one caller on the base branch and none now.

## What the unwatched tests leave unproven

- The three survivors in finding 7.
- Role surfaces for capped or inactive holders: no test exists.
- A contract-declaring user with a scoped `can()`: untested, and the opposite is pinned.
- `syncRoles` to a new role on a capped holder, and write atomicity.
- Key mode: the main suite runs on bigint keys; acting-for under UUID keys has one test.
- The Pennywise port matches Pennywise's code as read today. It does not model the firm-membership join, and an Agent whose owner row is missing is uncapped in Pennywise but capped to nothing here (closed).

## Dimensions and gaps in the brief

- **Simplification, abstraction, reuse.** Nothing beyond finding 9. Taxon's uuid helper is internal to taxon, so not reusing it is not a finding.
- **UX.** No user-facing surface. The two new exception messages are plain. The role × scope × endpoint walk is findings 1 and 2.
- **Design notes.** Stored throughs duplicate app state such as an Agent's owner id, so they can drift. A holder with three roles through one owner costs 31 queries on first check.
- **Brief.** It had no ownership table; I judged every fix as inside the module.

Evidence files:
- `/tmp/claude-1000/-home-ryanrobinson-dev-php-packages-robinsonryan-pennywise/d8a20ef1-1379-48cc-8ba8-d827b8ebed7d/scratchpad/probe1.log`
- `/tmp/claude-1000/-home-ryanrobinson-dev-php-packages-robinsonryan-pennywise/d8a20ef1-1379-48cc-8ba8-d827b8ebed7d/scratchpad/probe2.log`
- `/tmp/claude-1000/-home-ryanrobinson-dev-php-packages-robinsonryan-pennywise/d8a20ef1-1379-48cc-8ba8-d827b8ebed7d/scratchpad/ReviewProbeTest.php`
- `/tmp/claude-1000/-home-ryanrobinson-dev-php-packages-robinsonryan-pennywise/d8a20ef1-1379-48cc-8ba8-d827b8ebed7d/scratchpad/ReviewProbe2Test.php`
- `/tmp/claude-1000/-home-ryanrobinson-dev-php-packages-robinsonryan-pennywise/d8a20ef1-1379-48cc-8ba8-d827b8ebed7d/scratchpad/mutate.php` (mutation runner; results in `mut0.log`–`mut2.log` and `mutb0.log`–`mutb2.log` beside it)

```
Review: not written (report .md barred) · findings: /home/ryanrobinson/dev/php/packages/robinsonryan/permixion/.claude/worktrees/acting-for/docs/reviews/acting-for.findings.json — 9 findings (2 blocker, 2 major, 5 minor)
```

| # | Severity | file:line | Defect (one line) | Scope |
|---|---|---|---|---|
| 1 | BLOCKER | `src/Traits/HasRoles.php:100` | Role questions (hasRole family, role middleware, `@role`, getAllPermissions) admit revoked and capped holders | module |
| 2 | BLOCKER | `src/Traits/HasRoles.php:323` | A scope passed to `can()` is dropped at the gate hook; rights in the current team grant in the team asked about | module |
| 3 | MAJOR | `src/Traits/HasRoles.php:88` | `syncRoles()` to a different role replaces a capped assignment with an uncapped one | module |
| 4 | MAJOR | `src/Permixion.php:707` | Two routes to one role collapse silently: membership capped, one removal removes both | module |
| 5 | MINOR | `src/Permixion.php:711` | Assignment and throughs written without a transaction; a failure between leaves the role uncapped | module |
| 6 | MINOR | `src/PermixionServiceProvider.php:31` | Memo never reset outside requests and queue jobs; another process's revocation is not seen | module |
| 7 | MINOR | `tests/Feature/ActingForTest.php:113` | A re-assign that replaces throughs passes the suite; two memo guards unpinned | module |
| 8 | MINOR | `database/migrations/2024_01_05_000000_create_permixion_assignment_throughs_table.php:39` | `harness db-check` fails on the bigint primary key branch | module |
| 9 | MINOR | `src/Permixion.php:802` | `detachAllUserRoles()` and `userHasDirectPermission()` are dead after this diff | module |

