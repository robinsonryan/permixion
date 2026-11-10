<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use RobinsonRyan\Permixion\Facades\Permixion;
use RobinsonRyan\Permixion\GlobalScope;
use RobinsonRyan\Permixion\Tests\Fixtures\AccessGrant;
use RobinsonRyan\Permixion\Tests\Fixtures\Agent;
use RobinsonRyan\Permixion\Tests\Fixtures\ApiKey;
use RobinsonRyan\Permixion\Tests\Fixtures\ConnectedApp;
use RobinsonRyan\Permixion\Tests\Fixtures\GateUser;
use RobinsonRyan\Permixion\Tests\Fixtures\Team;

/*
|--------------------------------------------------------------------------
| Pennywise parity
|--------------------------------------------------------------------------
| Pennywise, the first consumer of "acting for", computes rights by hand:
| App\Enums\Role::abilities()/lesser()/higher(), App\Models\User::roleOn(),
| App\Ledger\Data\Principal::abilities(), App\Support\AgentPrincipal::of() and
| App\Http\Middleware\ResolvePrincipal. Each pw*() function below is a direct
| port of that code (read 2026-11-10) and is the oracle. Each rule is then
| expressed the permixion way (spec §4) and must give the oracle's answer, for
| every combination of its inputs.
|
| Company = a Team scope. Server Admin = the super-admin role, held globally,
| which needs teams.global_fallback so a global row reads under a Company.
*/

const PW_ABILITIES = ['read', 'draft', 'post', 'bank', 'master', 'close', 'access', 'server'];
const PW_ROLES = ['viewer', 'bookkeeper', 'accountant', 'owner'];

/** App\Enums\Role::abilities() */
function pwRoleAbilities(string $role): array
{
    return match ($role) {
        'viewer' => ['read'],
        'bookkeeper' => ['read', 'draft', 'post', 'bank', 'master'],
        'accountant' => [...pwRoleAbilities('bookkeeper'), 'close'],
        'owner' => [...pwRoleAbilities('accountant'), 'access'],
    };
}

/** App\Enums\Role::rank() */
function pwRank(string $role): int
{
    return array_search($role, PW_ROLES, true) + 1;
}

/** App\Enums\Role::lesser() */
function pwLesser(string $a, string $b): string
{
    return pwRank($a) <= pwRank($b) ? $a : $b;
}

/** App\Enums\Role::higher() */
function pwHigher(string $a, string $b): string
{
    return pwRank($a) >= pwRank($b) ? $a : $b;
}

/**
 * App\Models\User::roleOn(): the higher of a Membership and every Firm
 * assignment whose grant is not revoked, each counted no higher than its grant.
 *
 * @param  array<int, array{assigned: string, granted: string, revoked: bool}>  $firm
 */
function pwRoleOn(?string $membership, array $firm): ?string
{
    $roles = [];

    foreach ($firm as $route) {
        if (! $route['revoked']) {
            $roles[] = pwLesser($route['assigned'], $route['granted']);
        }
    }

    if ($membership !== null) {
        $roles[] = $membership;
    }

    return array_reduce($roles, fn (?string $best, string $role): string => $best === null ? $role : pwHigher($best, $role));
}

/**
 * Principal::abilities() for a signed-in User (ResolvePrincipal::user()). No
 * role and not Server Admin is a 404: no abilities at all.
 *
 * @param  array<int, array{assigned: string, granted: string, revoked: bool}>  $firm
 */
function pwUserAbilities(?string $membership, array $firm, bool $serverAdmin): array
{
    if ($serverAdmin) {
        return PW_ABILITIES;
    }

    $role = pwRoleOn($membership, $firm);

    return $role === null ? [] : pwRoleAbilities($role);
}

/**
 * AgentPrincipal::of() then Principal::abilities() for kind Agent. $owner is
 * 'none' (no owner), 'no-access', 'server-admin' or a Role.
 */
function pwAgentAbilities(string $role, string $owner, bool $draftsOnly, bool $revoked): array
{
    if ($revoked) {
        return [];
    }

    if ($owner === 'no-access') {
        return [];
    }

    if (in_array($owner, PW_ROLES, true)) {
        $role = pwLesser($role, $owner);
    }

    $abilities = pwRoleAbilities($role);

    if ($draftsOnly) {
        $abilities = array_values(array_filter($abilities, fn (string $a): bool => ! in_array($a, ['post', 'close'], true)));
    }

    return $abilities;
}

/**
 * ResolvePrincipal::app() then Principal::abilities() for kind App. A revoked
 * app is no Principal (401).
 */
function pwKeyAbilities(string $ceiling, string $tokenScope, ?string $asked, bool $appRevoked): array
{
    if ($appRevoked) {
        return [];
    }

    $role = $tokenScope === 'read' ? 'viewer' : $ceiling;
    $role = $asked === null ? $role : pwLesser($role, $asked);
    $abilities = pwRoleAbilities($role);

    if ($tokenScope === 'draft') {
        $abilities = array_values(array_filter($abilities, fn (string $a): bool => in_array($a, ['read', 'draft'], true)));
    }

    return $abilities;
}

/** What the holder may do on the Company, read back ability by ability. */
function permixionAbilities(object $holder, Team $company): array
{
    return array_values(array_filter(PW_ABILITIES, fn (string $a): bool => $holder->hasPermissionTo("pw.{$a}", $company)));
}

beforeEach(function (): void {
    config()->set('permixion.super_admin.role', 'server-admin');
    config()->set('permixion.teams.global_fallback', true);

    foreach (PW_ABILITIES as $ability) {
        Permixion::createPermission("pw.{$ability}");
    }

    foreach (PW_ROLES as $role) {
        Permixion::createRole($role, array_map(fn (string $a): string => "pw.{$a}", pwRoleAbilities($role)));
    }

    // Ceiling roles: an Agent in drafts-only mode loses post and close; a v1
    // `draft` key reads and drafts, nothing more.
    Permixion::createRole('agent-drafts-only', ['pw.read', 'pw.draft', 'pw.bank', 'pw.master', 'pw.access', 'pw.server']);
    Permixion::createRole('key-drafts-only', ['pw.read', 'pw.draft']);
    Permixion::createRole('system', ['pw.read', 'pw.draft', 'pw.bank', 'pw.master']);
    Permixion::createRole('server-admin');

    $this->company = Team::create(['name' => 'Acme Books']);
    $this->otherCompany = Team::create(['name' => 'Globex Books']);
    $this->serial = 0;

    $this->makeUser = function (?string $membership, bool $serverAdmin): GateUser {
        $user = GateUser::create(['name' => 'Person', 'email' => 'p'.(++$this->serial).'@example.com']);

        if ($membership !== null) {
            $user->assignRole($membership, $this->company);
        }

        if ($serverAdmin) {
            $user->assignRole('server-admin', GlobalScope::instance());
        }

        return $user;
    };
});

it('a person on a Company: Membership, Firm assignments under a grant, Server Admin', function (): void {
    $routes = [[]];

    foreach (PW_ROLES as $assigned) {
        foreach (PW_ROLES as $granted) {
            foreach ([false, true] as $revoked) {
                $routes[] = [['assigned' => $assigned, 'granted' => $granted, 'revoked' => $revoked]];
            }
        }
    }

    // Two firms granting the same person different routes onto one Company.
    $routes[] = [
        ['assigned' => 'owner', 'granted' => 'viewer', 'revoked' => false],
        ['assigned' => 'accountant', 'granted' => 'bookkeeper', 'revoked' => false],
    ];

    // Every Membership × every route; the Server Admin, who outranks them all,
    // against a sample of them (the full product doubles the run for nothing).
    $cases = [];

    foreach ([null, ...PW_ROLES] as $membership) {
        foreach ($routes as $firm) {
            $cases[] = [false, $membership, $firm];
        }
    }

    foreach ([null, 'viewer'] as $membership) {
        foreach (array_slice($routes, 0, 4) as $firm) {
            $cases[] = [true, $membership, $firm];
        }
    }

    $mismatches = [];

    foreach ($cases as [$serverAdmin, $membership, $firm]) {
        // One assignment per (holder, role, scope): a Membership and a
        // Firm route to the SAME role cannot both be stored. That case is
        // pinned separately below, not compared here.
        if (in_array($membership, array_column($firm, 'assigned'), true)) {
            continue;
        }

        $user = ($this->makeUser)($membership, $serverAdmin);

        foreach ($firm as $route) {
            $grant = AccessGrant::create(['name' => 'Grant', 'revoked_at' => $route['revoked'] ? now() : null]);
            $grant->assignRole($route['granted'], $this->company);
            $user->assignRole($route['assigned'], $this->company, [$grant]);
        }

        $expected = pwUserAbilities($membership, $firm, $serverAdmin);
        $actual = permixionAbilities($user, $this->company);

        if ($expected !== $actual) {
            $mismatches[] = ['serverAdmin' => $serverAdmin, 'membership' => $membership, 'firm' => $firm, 'expected' => $expected, 'actual' => $actual];
        }
    }

    expect($mismatches)->toBe([]);
});

it('an Agent: its Role capped by its owner\'s current Role, Server Admin owners imposing none', function (): void {
    $mismatches = [];

    foreach (PW_ROLES as $role) {
        foreach (['none', 'no-access', 'server-admin', ...PW_ROLES] as $owner) {
            foreach ([false, true] as $draftsOnly) {
                foreach ([false, true] as $revoked) {
                    $agent = Agent::create(['name' => 'Agent', 'revoked_at' => $revoked ? now() : null]);
                    $through = $draftsOnly ? ['agent-drafts-only'] : [];

                    if ($owner !== 'none') {
                        $through[] = ($this->makeUser)(
                            in_array($owner, PW_ROLES, true) ? $owner : null,
                            $owner === 'server-admin',
                        );
                    }

                    $agent->assignRole($role, $this->company, $through);

                    $expected = pwAgentAbilities($role, $owner, $draftsOnly, $revoked);
                    $actual = permixionAbilities($agent, $this->company);

                    if ($expected !== $actual) {
                        $mismatches[] = ['role' => $role, 'owner' => $owner, 'draftsOnly' => $draftsOnly, 'revoked' => $revoked, 'expected' => $expected, 'actual' => $actual];
                    }
                }
            }
        }
    }

    expect($mismatches)->toBe([]);
});

it('a Connected App\'s key: the app\'s ceiling, cut by token scope, cut again by the acting Role', function (): void {
    $mismatches = [];

    foreach (PW_ROLES as $ceiling) {
        foreach (['read', 'draft', 'post'] as $tokenScope) {
            foreach ([null, ...PW_ROLES] as $asked) {
                foreach ([false, true] as $appRevoked) {
                    $app = ConnectedApp::create(['name' => 'App', 'revoked_at' => $appRevoked ? now() : null]);
                    $app->assignRole($ceiling, $this->company);

                    $key = ApiKey::create(['name' => 'Key']);
                    $key->assignRole(
                        $tokenScope === 'read' ? 'viewer' : $ceiling,
                        $this->company,
                        $tokenScope === 'draft' ? [$app, 'key-drafts-only'] : [$app],
                    );

                    // The acting-person header: a request-scoped extra ceiling.
                    $acting = $asked === null ? $key : $key->withCeiling($asked);

                    $expected = pwKeyAbilities($ceiling, $tokenScope, $asked, $appRevoked);
                    $actual = permixionAbilities($acting, $this->company);

                    if ($expected !== $actual) {
                        $mismatches[] = ['ceiling' => $ceiling, 'tokenScope' => $tokenScope, 'asked' => $asked, 'appRevoked' => $appRevoked, 'expected' => $expected, 'actual' => $actual];
                    }
                }
            }
        }
    }

    expect($mismatches)->toBe([]);
});

it('a key for another Company grants nothing on this one', function (): void {
    $app = ConnectedApp::create(['name' => 'App']);
    $app->assignRole('owner', $this->otherCompany);
    $key = ApiKey::create(['name' => 'Key']);
    $key->assignRole('owner', $this->otherCompany, [$app]);

    expect(permixionAbilities($key, $this->company))->toBe([])
        ->and(permixionAbilities($key, $this->otherCompany))->toBe(pwRoleAbilities('owner'));
});

it('a process inside Pennywise reads, drafts, banks and masters — never posts or closes', function (): void {
    $process = Agent::create(['name' => 'system']);
    $process->assignRole('system', GlobalScope::instance());

    expect(permixionAbilities($process, $this->company))->toBe(['read', 'draft', 'bank', 'master']);
});

it('the Server Admin holds every right on every Company, through the Gate too', function (): void {
    config()->set('permixion.teams.enabled', true);
    config()->set('permixion.teams.resolver', 'callback');
    config()->set('permixion.teams.callback', fn (): Team => $this->company);

    $admin = ($this->makeUser)(null, true);

    expect(permixionAbilities($admin, $this->company))->toBe(PW_ABILITIES)
        ->and(permixionAbilities($admin, $this->otherCompany))->toBe(PW_ABILITIES)
        ->and(Gate::forUser($admin)->allows('pw.server'))->toBeTrue();
});

it('a person who leaves the Firm loses the route when the assignment is removed', function (): void {
    $user = ($this->makeUser)(null, false);
    $grant = AccessGrant::create(['name' => 'Grant']);
    $grant->assignRole('owner', $this->company);
    $user->assignRole('accountant', $this->company, [$grant]);

    expect(permixionAbilities($user, $this->company))->toBe(pwRoleAbilities('accountant'));

    $user->removeRole('accountant', $this->company);

    expect(permixionAbilities($user, $this->company))->toBe([]);
});

/*
 * The one Pennywise shape the storage cannot express. A Membership and a Firm
 * route to the SAME role are one assignment row (taxon's unique index), so the
 * route's grant caps the Membership too. Pennywise answers with the Membership
 * (the higher); permixion answers narrower. Pinned so the gap is visible: it
 * fails closed, never open.
 */
it('KNOWN GAP: a Membership and a Firm route to the same role collapse into one, capped, assignment', function (): void {
    $user = ($this->makeUser)('bookkeeper', false);
    $grant = AccessGrant::create(['name' => 'Grant']);
    $grant->assignRole('viewer', $this->company);
    $user->assignRole('bookkeeper', $this->company, [$grant]);

    $pennywise = pwUserAbilities('bookkeeper', [['assigned' => 'bookkeeper', 'granted' => 'viewer', 'revoked' => false]], false);

    expect($pennywise)->toBe(pwRoleAbilities('bookkeeper'))
        ->and(permixionAbilities($user, $this->company))->toBe(['read']);
});
