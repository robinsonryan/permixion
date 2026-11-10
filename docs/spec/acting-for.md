# Permixion — acting for (ceilings), and the bare-scope fix

Research: none — no external unknown (the design is read from permixion, taxon and Pennywise's rights code).

> **Status:** built 2026-11-10 on `feat/acting-for` (ledger below; P10 partial). Specced the same day for Pennywise, the first consumer. Ryan chose the direction (below); the rest are calls
> for veto. Acceptance contract: Appendix A. Ships as a **minor** version (`0.6.0`): the scope fix changes outcomes.

## 1. Purpose

Real apps grant rights to things that act **for** someone else: an AI Agent acting for the person who owns it, an API
key acting for the app it belongs to, a staffer of an accounting firm acting under what the client granted the firm, a
key restricted to drafting. Each must hold **no more than** what it acts for. Pennywise computes this by hand
(`Principal`, `AgentPrincipal`, `User::roleOn`, `ResolvePrincipal`); every app with agents or keys would write the
same code. Permixion gains it once.

Ryan, 2026-11-10: rights move onto permixion with an "acting-for" ceiling added to permixion (moving role storage
alone gains nothing); the bare-scope bug is fixed in a minor version.

## 2. Glossary

| Term | Definition | Avoid |
|---|---|---|
| **Holder** | Any Eloquent model with `HasRoles` that can be checked: a user, an Agent, an API key, an app. | "user" (when any holder is meant) |
| **Assignment** | A role held by a holder in a scope (today's taggable row). | "grant" (Pennywise uses Grant for Firm access) |
| **Through** | One or more limits attached to an assignment: a holder (the assignment is capped by what that holder may do in the same scope, recursively) or a role (a fixed ceiling). | "delegation", "proxy" |
| **Effective permissions** | What a holder may do in a scope: the union, over its active assignments there, of (the role's permissions ∩ every through's effective permissions). | — |
| **Ceiling** | A through that is a role rather than a holder. | "cap" in code |

"Lesser of two roles" is defined as the permissions both have in common (intersection), not a ranking, so it works for
apps whose roles are not nested; for nested roles (Pennywise's Viewer ⊂ Bookkeeper ⊂ Accountant ⊂ Owner) it equals
"the lower role".

## 3. Design

- `assignRole($role, ?Scope $scope = null, array $through = [])` — `$through` is a list of holders and/or roles.
  Stored with the assignment (a jsonb `through` column on taxon's taggables, or a permixion table keyed by the
  assignment — the builder decides after reading taxon's pivot, recorded). Existing assignments have none: unchanged
  behaviour.
- `effectivePermissions(?Scope $scope): Collection` on `HasRoles`; `hasPermissionTo` / `can` / `Gate::before` use it.
  Recursion through holders is cycle-safe (a holder already on the path contributes nothing) and depth-limited
  (config `permixion.through.max_depth`, default 5).
- **Inactive holders**: a holder may implement `RobinsonRyan\Permixion\Contracts\CanBeInactive { isActiveHolder(): bool }`
  (revoked keys, revoked Agents); an inactive holder has no permissions and caps anything through it to nothing.
- **Super-admin**: unchanged; a super-admin through-holder imposes no cap (its effective permissions are all).
- **Caching**: effective permissions are computed per request with a per-holder, per-scope memo; nothing is cached
  across requests that a role change could make stale.
- **Non-user holders**: documented and tested — an Agent model and a key model with `HasRoles` and `Authorizable`, checked
  through `Gate::forUser($holder)`; the scope resolver `callback` mode for apps whose scope comes from tenancy rather
  than a route parameter.
- **Bare-scope fix**: `can('x', $team)` scopes to `$team` exactly as `can('x', [$team])` does. Behaviour change → minor
  version, CHANGELOG warning naming the old behaviour.
- **Postgres**: the main suite moves to Postgres (the stack rule); SQLite is no longer the test database.

## 4. How Pennywise maps onto it (informative; Pennywise's own build adopts it)

| Pennywise rule today | Permixion |
|---|---|
| Membership role on a Company | assignment(user, role, scope Company) |
| Firm staffer: lesser of assignment role and the Firm's grant | assignment(user, role, Company, through [grant holder]); the grant is a holder whose assignment is the granted role |
| Agent: lesser of its role and its owner's current role; owner Server Admin → no cap | assignment(agent, role, Company, through [owner user]) |
| API key: its app's ceiling; Read-scope key → Viewer; drafts-only key → Read + Draft | assignment(key, ceiling role, Company, through [app]); drafts-only adds a ceiling role holding Read + Draft |
| Acting-person header narrows further | per-request extra ceiling: `$holder->withCeiling($role)` (immutable, request-scoped) |
| Server Admin: every right | super-admin role, global |
| Revoked keys/Agents/apps | `CanBeInactive` |
| Cross-Company key → 404 | unchanged in Pennywise (an assignment in another scope simply grants nothing) |

## 5. Testing decisions

Pest on Postgres (Testbench + DDEV `db`), prior art permixion's own `tests/Postgres/*`.

| Seam | Proves |
|---|---|
| Effective permissions (feature) | union over assignments; intersection through holders and ceilings; chains 3 deep; cycles; depth limit; inactive holders; super-admin through-holder; scope isolation |
| Gate and `can` (feature) | non-user holders via `Gate::forUser`; `Gate::before` uses effective permissions; bare and array scope argument agree |
| Pennywise parity (feature) | a table of Pennywise's rules (§4) expressed as fixtures yields exactly Pennywise's current answers |
| Regression | every existing permixion test passes on Postgres |

## 6. Decisions

| Decision | Choice | How settled |
|---|---|---|
| Add acting-for to permixion | yes | confirmed (Ryan) |
| Fix bare-scope `can()` | yes, minor version | confirmed (Ryan) |
| "Lesser" means intersection of permissions | yes | call |
| Through holds holders and roles, several per assignment | yes | call |
| Inactive holders via a contract | yes | call |
| Request-scoped extra ceiling (`withCeiling`) | yes | call |
| No cross-request cache of effective permissions | yes | call |
| Suite on Postgres | yes | call (stack rule) |
| Where throughs are stored | a permixion table, `permixion_assignment_throughs`, keyed by the assignment's `taggables.id`; one row per through (holder morph, or ceiling role id) | builder, after reading taxon's pivot. A jsonb column on `taggables` is a taxon schema change (taxon owns that table and its migrations), which the brief rules out; a table of permixion's own needs no taxon release, cleans up with the assignment by id, and lets a deleted ceiling role read as "names nothing" rather than silently vanishing from a json blob |
| Foreign keys on the throughs table | none; key columns follow `taxon.id_type`, `holder_id` is a string | builder: taxon *publishes* its migrations with a fresh timestamp, so in a consumer permixion's migration can run before `taggables` exists. Permixion deletes throughs with the assignment; an orphan names an id never reused and grants nothing |
| Re-assigning a held role with throughs | throughs are added (AND), never removed; re-assigning with none keeps them; `syncRoles` keeps retained roles in place | builder: a write never widens. Changing a cap = `removeRole` + `assignRole` |
| `syncRoles()` replacing a capped role | the replaced assignment's throughs carry to every role the sync newly assigns; retained roles keep their own; an optional `$through` caps every listed role; one transaction | orchestrator, from review finding 3: `syncRoles` is the usual "change role" call and assigned the new role uncapped. Carrying is the never-widen reading; to re-cap differently, `removeRole` first |
| A missing or deleted through (holder or role), an unknown `withCeiling` role | caps to nothing | builder: fail closed |
| Super admin under a cap | "unlimited" only when the super-admin role is held uncapped, by an active holder, under no ceiling; `Gate::before` asks effective permissions instead of `hasRole` | builder: otherwise an Agent assigned super-admin through a Viewer owner is god-mode |
| `effectivePermissions()` for an uncapped super admin | every defined permission name; checks still grant undefined abilities as before | builder: a Collection cannot say "everything" |
| `userHasPermission()` (engine, public) | answers from effective permissions | builder: otherwise it bypasses every cap |
| Memo | root results per holder + scope + the config that shapes them, in a `scoped` binding; flushed by every permixion write; the root's active state read live | builder: nested results depend on the path (cycles) and remaining depth, so only path-free root results are reused |
| `Gate::before` scope | the scope named in `$arguments` (`scopeFromArguments()`), else ambient (resolver) | orchestrator, from review finding 2 (was: ambient only). Ambient-only let `can('x', $teamB)`, refused in B by `HasRoles::can()` and handed on to the Gate, be granted on rights held in the ambient team. The two `GateBeforeTest` pins that encoded the cross-scope grant now pin the scoped answer; CHANGELOG *Changed* says so |
| Role questions under a cap (`hasRole` family, `role` / `role_or_permission` middleware, `@role` directives) | a role is **in force** only when the holder is active and the role survives its own assignment's throughs and the holder's `withCeiling()` ceilings whole (every permission of the role still granted); a role with no permissions only when nothing caps it; super admin only when unlimited. `getAllPermissions()` returns effective permissions; the raw stored list is `getAssignedRoleNames()`, which `syncRoles()` reconciles against | orchestrator, from review finding 1. Judged per assignment, not against the holder's whole effective set, so permissions another assignment adds back never revive a cut role; the empty-role rule keeps cycles, deleted, inactive and too-deep throughs failing closed for permissionless roles |
| Two routes to one role (Membership + Firm route, same role) | collapse into one assignment capped by every through — narrower than Pennywise | open: queued in `QUEUE.md`, pinned by a KNOWN GAP test |

## Appendix A — Requirements ledger

| ID | Requirement | Implementation | Tests | Status |
|----|-------------|----------------|-------|--------|
| P1 | `assignRole(..., through: [...])` stores holders and ceiling roles per assignment | `HasRoles::assignRole()`, `Permixion::attachRoleToUser()` / `resolveThroughs()` / `addThroughs()`, migration `2024_01_05_000000_create_permixion_assignment_throughs_table` | `ActingForTest` › storing throughs (11); `Uuid7RoleAssignmentTest` › acting for | Done |
| P2 | Effective permissions = ∪ over assignments (role ∩ each through's effective); used by `hasPermissionTo`, `can`, `Gate::before` | `Permixion::effectivePermissionsFor()` / `computeEffective()`, `EffectivePermissions`, `HasRoles::effectivePermissions()` / `hasPermissionTo()`, provider `Gate::before` | `ActingForTest` › effective permissions (16), engine (3); `NonUserHoldersTest` | Done |
| P3 | Cycle-safe, depth-limited recursion (`permixion.through.max_depth`) | `computeEffective()` path check, `throughPermissionSet()` depth check, config `through.max_depth` | `ActingForTest` › recursion (8) | Done |
| P4 | `CanBeInactive` holders grant nothing and cap through-dependents to nothing | `Contracts\CanBeInactive`, `Permixion::isActiveHolder()` | `ActingForTest` › inactive holders (5); `NonUserHoldersTest` revoked key | Done |
| P5 | Super-admin through-holder imposes no cap | `rolePermissionSet()` → `EffectivePermissions::unlimited()`, `intersect()` | `ActingForTest` › super-admin (4) | Done |
| P6 | `withCeiling($role)` request-scoped narrowing | `HasRoles::withCeiling()` / `permixionCeilings()`, applied in `effectivePermissionsFor()` | `ActingForTest` › withCeiling (8); `PennywiseParityTest` keys | Done |
| P7 | Non-user holders documented and tested via `Gate::forUser`; callback scope resolver documented | fixtures `Agent`, `ApiKey`, `ConnectedApp`, `AccessGrant`; README "Holders that are not users"; config `teams.callback` comment | `NonUserHoldersTest` (5) | Done |
| P8 | Bare-scope `can('x', $team)` scopes; CHANGELOG warning; minor version | `Permixion::scopeFromArguments()`, `HasRoles::can()`; CHANGELOG 0.6.0 *Changed* | `CanMethodTest` bare-scope (3) | Done (tag is the orchestrator's) |
| P9 | Main suite on Postgres | `tests/TestCase.php` (RefreshDatabase, `testing_wt_<slug>`), `tests/Fixtures/database/migrations`; savepoint fix in `Permixion::insertTag()` | whole suite, 191 tests | Done |
| P10 | Pennywise-parity fixture test (§4 table) | `tests/Feature/PennywiseParityTest.php` — a port of Pennywise's rules as the oracle, every input combination | `PennywiseParityTest` (8) | Partial — same-role Membership + Firm route collapses (KNOWN GAP test, `QUEUE.md`); "left the Firm" expressed as `removeRole` |
| P11 | README "Acting for" section; CHANGELOG | README "Acting for"; CHANGELOG 0.6.0; CLAUDE.md | — | Done |

## Appendix B — Non-goals

| ID | Non-goal | Status |
|----|----------|--------|
| PN1 | Migrating Pennywise onto permixion (Pennywise's own build) | Excluded |
| PN2 | Time-bound assignments (expiry dates) | Excluded |
| PN3 | A permissions UI | Excluded |
