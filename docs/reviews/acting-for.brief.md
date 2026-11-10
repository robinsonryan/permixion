# Review brief: permixion — acting-for ceilings and the bare-scope fix

Package `~/dev/php/packages/robinsonryan/permixion`. Review the diff `chore/stack-layers..feat/acting-for` (8d37460),
checked out read-only at `.claude/worktrees/acting-for`. Spec: `docs/spec/acting-for.md` there. Read the package
CLAUDE.md. Pennywise (first consumer) rules it must reproduce: `~/dev/services/pennywise/app/Enums/Role.php`,
`app/Ledger/Data/Principal.php`, `app/Support/AgentPrincipal.php`, `app/Models/User.php` (roleOn).

## Load-bearing invariant
A holder can never do more than every through allows: no check path (`can`, `hasPermissionTo`, `Gate::before`,
`hasRole`, `getAllPermissions`, the role/permission middleware, blade directives, `userHasPermission`, `syncRoles`,
super-admin handling) grants beyond the intersection; cycles, depth, deleted or inactive throughs and other scopes
fail closed; the bare-scope fix scopes exactly like the array form. Prove it holds, or show the path that leaks.

## Known — judge, don't just report
- hasRole(), getAllPermissions() and the role middleware ignore throughs and inactive holders (builder's note): is that
  a leak for real consumers?
- Two routes to the same role in one scope collapse to one capped assignment (fails closed vs Pennywise): judge the
  proposed fixes.

## Rules
Dimensions: the six fixed ones; security first; test integrity (the builder wrote the main tests before code but
did not watch them fail — say what that leaves unproven).
