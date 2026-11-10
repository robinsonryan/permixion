<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RobinsonRyan\Permixion\Exceptions\AssignmentRouteConflict;
use RobinsonRyan\Permixion\Exceptions\RoleDoesNotExist;
use RobinsonRyan\Permixion\Facades\Permixion;
use RobinsonRyan\Permixion\GlobalScope;
use RobinsonRyan\Permixion\Models\Permission;
use RobinsonRyan\Permixion\Tests\Fixtures\Agent;
use RobinsonRyan\Permixion\Tests\Fixtures\ApiKey;
use RobinsonRyan\Permixion\Tests\Fixtures\ConnectedApp;
use RobinsonRyan\Permixion\Tests\Fixtures\GateUser;
use RobinsonRyan\Permixion\Tests\Fixtures\Team;

/*
|--------------------------------------------------------------------------
| Acting for — assignments capped by what they act through
|--------------------------------------------------------------------------
| A holder's effective permissions in a scope are the union, over its
| assignments there, of (the role's permissions ∩ every through's effective
| permissions). A through is a holder (capped by what that holder may do in the
| same scope, recursively) or a role (a fixed ceiling).
|
| This is authorization code: every test that proves a grant has a sibling
| proving the matching refusal.
*/

beforeEach(function (): void {
    foreach (['ledger.read', 'ledger.draft', 'ledger.post', 'ledger.close', 'reports.view', 'reports.export'] as $permission) {
        Permixion::createPermission($permission);
    }

    Permixion::createRole('viewer', ['ledger.read']);
    Permixion::createRole('drafter', ['ledger.read', 'ledger.draft']);
    Permixion::createRole('bookkeeper', ['ledger.read', 'ledger.draft', 'ledger.post']);
    Permixion::createRole('accountant', ['ledger.read', 'ledger.draft', 'ledger.post', 'ledger.close']);
    Permixion::createRole('reporter', ['reports.view', 'reports.export']);
    Permixion::createRole('super-admin');

    $this->team = Team::create(['name' => 'Acme']);
    $this->otherTeam = Team::create(['name' => 'Globex']);

    $this->owner = GateUser::create(['name' => 'Owner', 'email' => 'owner@example.com']);
    $this->agent = Agent::create(['name' => 'Agent']);
});

/** @param  iterable<string>  $names */
function sortedNames(iterable $names): array
{
    $names = collect($names)->values()->all();
    sort($names);

    return $names;
}

describe('storing throughs with an assignment', function (): void {
    it('stores a holder through against the assignment row', function (): void {
        $this->agent->assignRole('accountant', $this->team, [$this->owner]);

        $assignmentId = DB::table('taggables')
            ->where('taggable_type', $this->agent->getMorphClass())
            ->where('taggable_id', $this->agent->getKey())
            ->value('id');

        $this->assertDatabaseHas('permixion_assignment_throughs', [
            'assignment_id' => $assignmentId,
            'holder_type' => $this->owner->getMorphClass(),
            'holder_id' => (string) $this->owner->getKey(),
            'role_id' => null,
        ]);
    });

    it('stores a ceiling role through against the assignment row', function (): void {
        $this->agent->assignRole('accountant', $this->team, ['drafter']);

        $this->assertDatabaseHas('permixion_assignment_throughs', [
            'holder_type' => null,
            'holder_id' => null,
            'role_id' => Permixion::findRoleOrFail('drafter')->tag()->getKey(),
        ]);
    });

    it('stores several throughs, holders and roles mixed', function (): void {
        $this->agent->assignRole('accountant', $this->team, [$this->owner, Permixion::findRoleOrFail('drafter')]);

        expect(DB::table('permixion_assignment_throughs')->count())->toBe(2);
    });

    it('stores nothing for an assignment without throughs, which behaves as before', function (): void {
        $this->agent->assignRole('accountant', $this->team);

        expect(DB::table('permixion_assignment_throughs')->count())->toBe(0)
            ->and($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeTrue();
    });

    it('drops the throughs when the assignment is removed', function (): void {
        $this->agent->assignRole('accountant', $this->team, [$this->owner, 'drafter']);

        $this->agent->removeRole('accountant', $this->team);

        expect(DB::table('permixion_assignment_throughs')->count())->toBe(0);
    });

    it('refuses to re-assign a capped role uncapped — two routes to one role never merge silently', function (): void {
        $this->agent->assignRole('accountant', $this->team, ['drafter']);

        expect(fn () => $this->agent->assignRole('accountant', $this->team))
            ->toThrow(AssignmentRouteConflict::class, "'accountant'");

        expect($this->agent->hasPermissionTo('ledger.post', $this->team))->toBeFalse()
            ->and(DB::table('permixion_assignment_throughs')->count())->toBe(1);
    });

    it('refuses to cap a role already held uncapped, leaving that assignment whole', function (): void {
        $this->agent->assignRole('accountant', $this->team);

        expect(fn () => $this->agent->assignRole('accountant', $this->team, [$this->owner]))
            ->toThrow(AssignmentRouteConflict::class, "'accountant'");

        expect($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeTrue()
            ->and(DB::table('permixion_assignment_throughs')->count())->toBe(0);
    });

    it('re-assigning an uncapped role uncapped, or a capped one with throughs, is not a conflict', function (): void {
        $this->agent->assignRole('accountant', $this->team);
        $this->agent->assignRole('accountant', $this->team);
        $this->agent->assignRole('viewer', $this->team, ['drafter']);
        $this->agent->assignRole('viewer', $this->team, ['drafter']);

        expect(DB::table('permixion_assignment_throughs')->count())->toBe(1)
            ->and($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeTrue();
    });

    it('the same role in another scope is another assignment, never a conflict', function (): void {
        $this->agent->assignRole('accountant', $this->team);
        $this->agent->assignRole('accountant', $this->otherTeam, ['viewer']);

        expect($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeTrue()
            ->and($this->agent->hasPermissionTo('ledger.close', $this->otherTeam))->toBeFalse();
    });

    it('a syncRoles that would cap a role held uncapped is refused and changes nothing', function (): void {
        $this->agent->assignRole(['accountant', 'reporter'], $this->team);

        expect(fn () => $this->agent->syncRoles(['accountant'], $this->team, ['viewer']))
            ->toThrow(AssignmentRouteConflict::class);

        expect(sortedNames($this->agent->getAssignedRoleNames($this->team)))->toBe(['accountant', 'reporter'])
            ->and($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeTrue();
    });

    it('re-assigning with another through adds it, narrowing further', function (): void {
        $this->agent->assignRole('accountant', $this->team, ['bookkeeper']);
        $this->agent->assignRole('accountant', $this->team, ['drafter']);

        expect(sortedNames($this->agent->effectivePermissions($this->team)))->toBe(['ledger.draft', 'ledger.read']);
    });

    it('syncRoles keeps the cap on a role it retains', function (): void {
        $this->agent->assignRole('accountant', $this->team, ['drafter']);

        $this->agent->syncRoles(['accountant', 'reporter'], $this->team);

        expect($this->agent->hasPermissionTo('ledger.post', $this->team))->toBeFalse()
            ->and($this->agent->hasPermissionTo('ledger.draft', $this->team))->toBeTrue()
            ->and($this->agent->hasPermissionTo('reports.view', $this->team))->toBeTrue();
    });

    it('syncRoles still removes roles left out of the list, its own through rows with them', function (): void {
        $this->agent->assignRole('accountant', $this->team, ['drafter']);
        $accountantRow = DB::table('taggables')->where('taggable_id', $this->agent->getKey())->value('id');

        $this->agent->syncRoles(['reporter'], $this->team);

        $reporterRow = DB::table('taggables')->where('taggable_id', $this->agent->getKey())->value('id');

        expect($this->agent->getAssignedRoleNames($this->team))->toBe(['reporter'])
            ->and(DB::table('permixion_assignment_throughs')->where('assignment_id', $accountantRow)->count())->toBe(0)
            // The drafter cap moved to reporter, which it cuts to nothing.
            ->and(DB::table('permixion_assignment_throughs')->where('assignment_id', $reporterRow)->count())->toBe(1)
            ->and($this->agent->effectivePermissions($this->team))->toBeEmpty();
    });

    it('syncRoles to a different role carries the replaced assignment\'s throughs to it — never widening', function (): void {
        $this->owner->assignRole('viewer', $this->team);
        $this->agent->assignRole('accountant', $this->team, [$this->owner]);

        $this->agent->syncRoles(['bookkeeper'], $this->team);

        expect(sortedNames($this->agent->effectivePermissions($this->team)))->toBe(['ledger.read'])
            ->and($this->agent->getAssignedRoleNames($this->team))->toBe(['bookkeeper'])
            ->and($this->agent->hasPermissionTo('ledger.post', $this->team))->toBeFalse();

        // The cap follows the owner, as it did on the replaced role.
        $this->owner->syncRoles(['bookkeeper'], $this->team);

        expect(sortedNames($this->agent->effectivePermissions($this->team)))->toBe(['ledger.draft', 'ledger.post', 'ledger.read']);
    });

    it('syncRoles carries the throughs of every replaced capped assignment, which all apply', function (): void {
        Permixion::createRole('poster', ['ledger.read', 'ledger.post']);
        $this->agent->assignRole('drafter', $this->team, ['bookkeeper']);
        $this->agent->assignRole('reporter', $this->team, ['poster']);

        $this->agent->syncRoles(['accountant'], $this->team);

        expect(sortedNames($this->agent->effectivePermissions($this->team)))->toBe(['ledger.post', 'ledger.read']);
    });

    it('syncRoles to two new roles caps both by everything the replaced assignments acted through', function (): void {
        Permixion::createRole('poster', ['ledger.read', 'ledger.post']);
        $this->agent->assignRole('accountant', $this->team, ['bookkeeper']);
        $this->agent->assignRole('drafter', $this->team, ['poster']);

        $this->agent->syncRoles(['accountant', 'reporter'], $this->team);

        // accountant is retained with its own cap; reporter is new and carries
        // drafter's (poster), which cuts reports to nothing.
        expect(sortedNames($this->agent->effectivePermissions($this->team)))->toBe(['ledger.draft', 'ledger.post', 'ledger.read']);
    });

    it('syncRoles replacing only uncapped roles assigns the new ones uncapped', function (): void {
        $this->agent->assignRole('viewer', $this->team);

        $this->agent->syncRoles(['accountant'], $this->team);

        expect($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeTrue()
            ->and(DB::table('permixion_assignment_throughs')->count())->toBe(0);
    });

    it('syncRoles takes throughs of its own, applied to every role it leaves', function (): void {
        $this->agent->syncRoles(['accountant', 'reporter'], $this->team, ['drafter']);

        expect(sortedNames($this->agent->effectivePermissions($this->team)))->toBe(['ledger.draft', 'ledger.read'])
            ->and($this->agent->hasPermissionTo('reports.view', $this->team))->toBeFalse();
    });

    it('syncRoles throughs narrow a retained capped role further', function (): void {
        $this->agent->assignRole('accountant', $this->team, ['bookkeeper']);

        $this->agent->syncRoles(['accountant'], $this->team, ['drafter']);

        expect(sortedNames($this->agent->effectivePermissions($this->team)))->toBe(['ledger.draft', 'ledger.read']);
    });

    it('refuses an unsaved holder as a through', function (): void {
        $unsaved = new GateUser(['name' => 'Unsaved', 'email' => 'unsaved@example.com']);

        expect(fn () => $this->agent->assignRole('accountant', $this->team, [$unsaved]))
            ->toThrow(InvalidArgumentException::class);

        expect($this->agent->hasRole('accountant', $this->team))->toBeFalse();
    });

    it('refuses an unknown ceiling role in strict mode', function (): void {
        expect(fn () => $this->agent->assignRole('accountant', $this->team, ['no-such-role']))
            ->toThrow(RoleDoesNotExist::class);

        expect($this->agent->hasRole('accountant', $this->team))->toBeFalse();
    });
});

describe('effective permissions', function (): void {
    it('is the union over the holder\'s assignments in the scope', function (): void {
        $this->agent->assignRole(['viewer', 'reporter'], $this->team);

        expect(sortedNames($this->agent->effectivePermissions($this->team)))
            ->toBe(['ledger.read', 'reports.export', 'reports.view']);
    });

    it('intersects an assignment with what its through holder may do in the same scope', function (): void {
        $this->owner->assignRole('bookkeeper', $this->team);
        $this->agent->assignRole('accountant', $this->team, [$this->owner]);

        expect(sortedNames($this->agent->effectivePermissions($this->team)))
            ->toBe(['ledger.draft', 'ledger.post', 'ledger.read'])
            ->and($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeFalse();
    });

    it('is capped by the lower side whichever side is lower', function (): void {
        $this->owner->assignRole('accountant', $this->team);
        $this->agent->assignRole('viewer', $this->team, [$this->owner]);

        expect(sortedNames($this->agent->effectivePermissions($this->team)))->toBe(['ledger.read']);
    });

    it('intersects with a ceiling role', function (): void {
        $this->agent->assignRole('accountant', $this->team, ['drafter']);

        expect(sortedNames($this->agent->effectivePermissions($this->team)))->toBe(['ledger.draft', 'ledger.read']);
    });

    it('intersects with every through on the assignment', function (): void {
        $this->owner->assignRole('bookkeeper', $this->team);
        Permixion::createRole('poster', ['ledger.read', 'ledger.post']);

        $this->agent->assignRole('accountant', $this->team, [$this->owner, 'poster']);

        expect(sortedNames($this->agent->effectivePermissions($this->team)))->toBe(['ledger.post', 'ledger.read']);
    });

    it('lets an uncapped assignment grant beside a capped one', function (): void {
        $this->agent->assignRole('accountant', $this->team, ['viewer']);
        $this->agent->assignRole('reporter', $this->team);

        expect(sortedNames($this->agent->effectivePermissions($this->team)))
            ->toBe(['ledger.read', 'reports.export', 'reports.view']);
    });

    it('includes direct permissions, which carry no through', function (): void {
        $this->agent->assignRole('accountant', $this->team, ['viewer']);
        $this->agent->givePermissionTo('reports.view', $this->team);

        expect(sortedNames($this->agent->effectivePermissions($this->team)))->toBe(['ledger.read', 'reports.view']);
    });

    it('counts a through holder\'s direct permissions as part of what it may do', function (): void {
        $this->owner->givePermissionTo(['ledger.read', 'ledger.close'], $this->team);
        $this->agent->assignRole('accountant', $this->team, [$this->owner]);

        expect(sortedNames($this->agent->effectivePermissions($this->team)))->toBe(['ledger.close', 'ledger.read']);
    });

    it('caps a through holder to its rights in THIS scope — another scope leaks nothing', function (): void {
        $this->owner->assignRole('accountant', $this->otherTeam);
        $this->owner->assignRole('viewer', $this->team);
        $this->agent->assignRole('accountant', $this->team, [$this->owner]);

        expect(sortedNames($this->agent->effectivePermissions($this->team)))->toBe(['ledger.read'])
            ->and($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeFalse();
    });

    it('a through holder with no rights in the scope leaves nothing', function (): void {
        $this->owner->assignRole('accountant', $this->otherTeam);
        $this->agent->assignRole('accountant', $this->team, [$this->owner]);

        expect($this->agent->effectivePermissions($this->team))->toBeEmpty()
            ->and($this->agent->hasPermissionTo('ledger.read', $this->team))->toBeFalse();
    });

    it('an assignment in another scope grants nothing here', function (): void {
        $this->agent->assignRole('accountant', $this->otherTeam);

        expect($this->agent->effectivePermissions($this->team))->toBeEmpty();
    });

    it('intersects wildcard patterns to the narrower side', function (): void {
        Permixion::createPermission('ledger.*');
        Permixion::createPermission('reports.*');
        Permixion::createRole('ledger-admin', ['ledger.*']);
        Permixion::createRole('everything', ['ledger.*', 'reports.*']);

        $this->agent->assignRole('ledger-admin', $this->team, ['drafter']);
        $this->owner->assignRole('everything', $this->team);
        $agentB = Agent::create(['name' => 'B']);
        $agentB->assignRole('bookkeeper', $this->team, [$this->owner]);
        $agentC = Agent::create(['name' => 'C']);
        $agentC->assignRole('ledger-admin', $this->team, ['reporter']);

        expect(sortedNames($this->agent->effectivePermissions($this->team)))->toBe(['ledger.draft', 'ledger.read'])
            ->and($this->agent->hasPermissionTo('ledger.post', $this->team))->toBeFalse()
            ->and(sortedNames($agentB->effectivePermissions($this->team)))->toBe(['ledger.draft', 'ledger.post', 'ledger.read'])
            ->and($agentB->hasPermissionTo('ledger.close', $this->team))->toBeFalse()
            ->and($agentC->effectivePermissions($this->team))->toBeEmpty();
    });

    it('sees a role change made earlier in the same request', function (): void {
        $this->owner->assignRole('accountant', $this->team);
        $this->agent->assignRole('accountant', $this->team, [$this->owner]);

        expect($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeTrue();

        $this->owner->removeRole('accountant', $this->team);

        expect($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeFalse();

        $this->owner->assignRole('viewer', $this->team);

        expect($this->agent->hasPermissionTo('ledger.read', $this->team))->toBeTrue()
            ->and($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeFalse();
    });

    it('sees a role\'s permissions change made earlier in the same request', function (): void {
        $this->agent->assignRole('accountant', $this->team, ['drafter']);

        expect($this->agent->hasPermissionTo('ledger.draft', $this->team))->toBeTrue();

        Permixion::findRoleOrFail('drafter')->revokePermissionTo('ledger.draft');

        expect($this->agent->hasPermissionTo('ledger.draft', $this->team))->toBeFalse();
    });

    it('a deleted through holder caps to nothing', function (): void {
        $this->owner->assignRole('accountant', $this->team);
        $this->agent->assignRole('accountant', $this->team, [$this->owner]);

        $this->owner->delete();
        Permixion::flushEffectivePermissions();

        expect($this->agent->effectivePermissions($this->team))->toBeEmpty();
    });

    it('a deleted ceiling role caps to nothing rather than lifting the cap', function (): void {
        $this->agent->assignRole('accountant', $this->team, ['drafter']);

        Permixion::deleteRole('drafter');

        expect($this->agent->effectivePermissions($this->team))->toBeEmpty()
            ->and($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeFalse();
    });
});

describe('recursion', function (): void {
    it('follows a chain three holders deep', function (): void {
        $app = ConnectedApp::create(['name' => 'App']);
        $key = ApiKey::create(['name' => 'Key']);

        $this->owner->assignRole('bookkeeper', $this->team);
        $app->assignRole('accountant', $this->team, [$this->owner]);
        $key->assignRole('accountant', $this->team, [$app]);
        $this->agent->assignRole('accountant', $this->team, [$key]);

        expect(sortedNames($this->agent->effectivePermissions($this->team)))->toBe(['ledger.draft', 'ledger.post', 'ledger.read'])
            ->and($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeFalse();
    });

    it('a cap anywhere along the chain reaches the end of it', function (): void {
        $app = ConnectedApp::create(['name' => 'App']);
        $key = ApiKey::create(['name' => 'Key']);

        $this->owner->assignRole('accountant', $this->team);
        $app->assignRole('accountant', $this->team, [$this->owner, 'viewer']);
        $key->assignRole('accountant', $this->team, [$app]);

        expect(sortedNames($key->effectivePermissions($this->team)))->toBe(['ledger.read']);
    });

    it('a two-holder cycle grants nothing to either, and terminates', function (): void {
        $other = Agent::create(['name' => 'Other']);
        $this->agent->assignRole('accountant', $this->team, [$other]);
        $other->assignRole('accountant', $this->team, [$this->agent]);

        expect($this->agent->effectivePermissions($this->team))->toBeEmpty()
            ->and($other->effectivePermissions($this->team))->toBeEmpty()
            ->and($this->agent->hasPermissionTo('ledger.read', $this->team))->toBeFalse();
    });

    it('a holder acting through itself grants nothing on that assignment', function (): void {
        $this->agent->assignRole('accountant', $this->team, [$this->agent]);

        expect($this->agent->effectivePermissions($this->team))->toBeEmpty();
    });

    it('a cycle leaks nothing, even when the cycle passes through a holder with rights', function (): void {
        // agent -> owner -> agent: the owner's own capped assignment contributes
        // nothing, but its uncapped one still does — and caps the agent to it.
        $this->owner->assignRole('viewer', $this->team);
        $this->owner->assignRole('accountant', $this->team, [$this->agent]);
        $this->agent->assignRole('accountant', $this->team, [$this->owner]);

        expect(sortedNames($this->agent->effectivePermissions($this->team)))->toBe(['ledger.read'])
            ->and($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeFalse();
    });

    it('an uncapped assignment survives a cycle on another assignment', function (): void {
        $other = Agent::create(['name' => 'Other']);
        $this->agent->assignRole('accountant', $this->team, [$other]);
        $other->assignRole('accountant', $this->team, [$this->agent]);
        $this->agent->assignRole('reporter', $this->team);

        expect(sortedNames($this->agent->effectivePermissions($this->team)))->toBe(['reports.export', 'reports.view']);
    });

    it('a chain deeper than permixion.through.max_depth grants nothing', function (): void {
        config()->set('permixion.through.max_depth', 2);

        $app = ConnectedApp::create(['name' => 'App']);
        $key = ApiKey::create(['name' => 'Key']);

        $this->owner->assignRole('accountant', $this->team);
        $app->assignRole('accountant', $this->team, [$this->owner]);
        $key->assignRole('accountant', $this->team, [$app]);
        $this->agent->assignRole('accountant', $this->team, [$key]);

        // key -> app -> owner is two hops: allowed. agent -> key -> app -> owner is three.
        expect(sortedNames($key->effectivePermissions($this->team)))->toBe(['ledger.close', 'ledger.draft', 'ledger.post', 'ledger.read'])
            ->and($this->agent->effectivePermissions($this->team))->toBeEmpty()
            ->and($this->agent->hasPermissionTo('ledger.read', $this->team))->toBeFalse();
    });

    it('defaults permixion.through.max_depth to 5', function (): void {
        expect(config('permixion.through.max_depth'))->toBe(5);

        $holders = [$this->owner];
        $this->owner->assignRole('accountant', $this->team);

        foreach (range(1, 6) as $i) {
            $next = Agent::create(['name' => "Hop {$i}"]);
            $next->assignRole('accountant', $this->team, [end($holders)]);
            $holders[] = $next;
        }

        // $holders[5] is five hops from the owner; $holders[6] is six.
        expect($holders[5]->hasPermissionTo('ledger.close', $this->team))->toBeTrue()
            ->and($holders[6]->hasPermissionTo('ledger.close', $this->team))->toBeFalse();
    });
});

describe('inactive holders', function (): void {
    it('an inactive holder has no permissions at all', function (): void {
        $this->agent->assignRole('accountant', $this->team);
        $this->agent->givePermissionTo('reports.view', $this->team);
        $this->agent->update(['revoked_at' => now()]);

        expect($this->agent->effectivePermissions($this->team))->toBeEmpty()
            ->and($this->agent->hasPermissionTo('ledger.read', $this->team))->toBeFalse()
            ->and($this->agent->can('ledger.read', $this->team))->toBeFalse();
    });

    it('an inactive holder holding super-admin is not god-mode', function (): void {
        config()->set('permixion.teams.enabled', true);
        config()->set('permixion.teams.resolver', 'callback');
        config()->set('permixion.teams.callback', fn () => $this->team);

        $this->agent->assignRole('super-admin', $this->team);
        $this->agent->update(['revoked_at' => now()]);

        expect(Gate::forUser($this->agent)->allows('anything.at.all'))->toBeFalse()
            ->and($this->agent->hasPermissionTo('ledger.read', $this->team))->toBeFalse();
    });

    it('an inactive through holder caps everything acting through it to nothing', function (): void {
        $app = ConnectedApp::create(['name' => 'App']);
        $key = ApiKey::create(['name' => 'Key']);
        $app->assignRole('accountant', $this->team);
        $key->assignRole('accountant', $this->team, [$app]);

        expect($key->hasPermissionTo('ledger.read', $this->team))->toBeTrue();

        $app->update(['revoked_at' => now()]);
        Permixion::flushEffectivePermissions();

        expect($key->effectivePermissions($this->team))->toBeEmpty()
            ->and($key->hasPermissionTo('ledger.read', $this->team))->toBeFalse();
    });

    it('an inactive holder deep in a chain caps the end of it', function (): void {
        $app = ConnectedApp::create(['name' => 'App', 'revoked_at' => now()]);
        $key = ApiKey::create(['name' => 'Key']);

        $this->owner->assignRole('accountant', $this->team);
        $app->assignRole('accountant', $this->team, [$this->owner]);
        $key->assignRole('accountant', $this->team, [$app]);
        $this->agent->assignRole('accountant', $this->team, [$key]);

        expect($this->agent->effectivePermissions($this->team))->toBeEmpty();
    });

    it('reading the active state is live for the holder being checked', function (): void {
        $this->agent->assignRole('accountant', $this->team);

        expect($this->agent->hasPermissionTo('ledger.read', $this->team))->toBeTrue();

        $this->agent->revoked_at = now();

        expect($this->agent->hasPermissionTo('ledger.read', $this->team))->toBeFalse();
    });
});

describe('super-admin', function (): void {
    it('a super-admin through holder imposes no cap', function (): void {
        $this->owner->assignRole('super-admin', $this->team);
        $this->agent->assignRole('accountant', $this->team, [$this->owner]);

        expect(sortedNames($this->agent->effectivePermissions($this->team)))
            ->toBe(['ledger.close', 'ledger.draft', 'ledger.post', 'ledger.read']);
    });

    it('a super-admin through holder lifts nothing the assignment\'s own role lacks', function (): void {
        $this->owner->assignRole('super-admin', $this->team);
        $this->agent->assignRole('viewer', $this->team, [$this->owner]);

        expect(sortedNames($this->agent->effectivePermissions($this->team)))->toBe(['ledger.read'])
            ->and(Gate::forUser($this->agent)->allows('anything.at.all'))->toBeFalse();
    });

    it('a super-admin role held through a capped holder is not god-mode', function (): void {
        config()->set('permixion.teams.enabled', true);
        config()->set('permixion.teams.resolver', 'callback');
        config()->set('permixion.teams.callback', fn () => $this->team);

        $this->owner->assignRole('viewer', $this->team);
        $this->agent->assignRole('super-admin', $this->team, [$this->owner]);

        expect(sortedNames($this->agent->effectivePermissions($this->team)))->toBe(['ledger.read'])
            ->and(Gate::forUser($this->agent)->allows('anything.at.all'))->toBeFalse()
            ->and(Gate::forUser($this->agent)->allows('ledger.close'))->toBeFalse()
            ->and(Gate::forUser($this->agent)->allows('ledger.read'))->toBeTrue();
    });

    it('an uncapped super-admin is still granted everything', function (): void {
        config()->set('permixion.teams.enabled', true);
        config()->set('permixion.teams.resolver', 'callback');
        config()->set('permixion.teams.callback', fn () => $this->team);

        $this->agent->assignRole('super-admin', $this->team);

        expect(Gate::forUser($this->agent)->allows('anything.at.all'))->toBeTrue()
            ->and(sortedNames($this->agent->effectivePermissions($this->team)))
            ->toBe(sortedNames(array_map(fn (Permission $p): string => $p->name, Permixion::getAllPermissions())));
    });
});

describe('withCeiling', function (): void {
    it('narrows the holder it returns', function (): void {
        $this->agent->assignRole('accountant', $this->team);

        $narrowed = $this->agent->withCeiling('viewer');

        expect(sortedNames($narrowed->effectivePermissions($this->team)))->toBe(['ledger.read'])
            ->and($narrowed->hasPermissionTo('ledger.post', $this->team))->toBeFalse()
            ->and($narrowed->can('ledger.post', $this->team))->toBeFalse();
    });

    it('leaves the original holder untouched', function (): void {
        $this->agent->assignRole('accountant', $this->team);

        $this->agent->withCeiling('viewer');

        expect($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeTrue();
    });

    it('never widens', function (): void {
        $this->agent->assignRole('viewer', $this->team);

        expect(sortedNames($this->agent->withCeiling('accountant')->effectivePermissions($this->team)))->toBe(['ledger.read']);
    });

    it('stacks: two ceilings intersect', function (): void {
        Permixion::createRole('poster', ['ledger.read', 'ledger.post']);
        $this->agent->assignRole('accountant', $this->team);

        $narrowed = $this->agent->withCeiling('bookkeeper')->withCeiling('poster');

        expect(sortedNames($narrowed->effectivePermissions($this->team)))->toBe(['ledger.post', 'ledger.read']);
    });

    it('also caps direct permissions', function (): void {
        $this->agent->givePermissionTo('reports.view', $this->team);

        expect($this->agent->withCeiling('viewer')->hasPermissionTo('reports.view', $this->team))->toBeFalse();
    });

    it('narrows a super-admin, whose Gate god-mode it removes', function (): void {
        config()->set('permixion.teams.enabled', true);
        config()->set('permixion.teams.resolver', 'callback');
        config()->set('permixion.teams.callback', fn () => $this->team);

        $this->owner->assignRole('super-admin', $this->team);
        $narrowed = $this->owner->withCeiling('viewer');

        expect(Gate::forUser($narrowed)->allows('anything.at.all'))->toBeFalse()
            ->and(Gate::forUser($narrowed)->allows('ledger.post'))->toBeFalse()
            ->and(Gate::forUser($narrowed)->allows('ledger.read'))->toBeTrue()
            ->and(Gate::forUser($this->owner)->allows('anything.at.all'))->toBeTrue();
    });

    it('an unknown ceiling role caps to nothing', function (): void {
        $this->agent->assignRole('accountant', $this->team);

        expect($this->agent->withCeiling('no-such-role')->effectivePermissions($this->team))->toBeEmpty();
    });

    it('is honoured through Gate::forUser and the engine', function (): void {
        config()->set('permixion.teams.enabled', true);
        config()->set('permixion.teams.resolver', 'callback');
        config()->set('permixion.teams.callback', fn () => $this->team);

        $this->agent->assignRole('accountant', $this->team);
        $narrowed = $this->agent->withCeiling('viewer');

        expect(Gate::forUser($narrowed)->allows('ledger.post'))->toBeFalse()
            ->and(Gate::forUser($narrowed)->allows('ledger.read'))->toBeTrue()
            ->and(Permixion::userHasPermission($narrowed, 'ledger.post', $this->team))->toBeFalse();
    });
});

describe('the engine and the scope sentinels', function (): void {
    it('userHasPermission honours throughs too', function (): void {
        $this->agent->assignRole('accountant', $this->team, ['viewer']);

        expect(Permixion::userHasPermission($this->agent, 'ledger.post', $this->team))->toBeFalse()
            ->and(Permixion::userHasPermission($this->agent, 'ledger.read', $this->team))->toBeTrue();
    });

    it('global_fallback lets a global through holder role cap a scoped assignment', function (): void {
        config()->set('permixion.teams.global_fallback', true);

        $this->owner->assignRole('bookkeeper', GlobalScope::instance());
        $this->agent->assignRole('accountant', $this->team, [$this->owner]);

        expect(sortedNames($this->agent->effectivePermissions($this->team)))->toBe(['ledger.draft', 'ledger.post', 'ledger.read']);
    });

    it('without global_fallback a global through holder role caps a scoped assignment to nothing', function (): void {
        $this->owner->assignRole('bookkeeper', GlobalScope::instance());
        $this->agent->assignRole('accountant', $this->team, [$this->owner]);

        expect($this->agent->effectivePermissions($this->team))->toBeEmpty();
    });
});

describe('atomic writes and a consistent read', function (): void {
    /*
    | An absent through row reads as "uncapped". So an assignment must never be
    | stored, or read, without the throughs it carries. The failures below are
    | injected by a query listener: it runs after a statement has executed, so
    | throwing from it is a failure between two writes.
    */

    it('a failure after the assignment row is written leaves no assignment behind', function (): void {
        $armed = true;
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && str_starts_with($query->sql, 'insert into "taggables"')) {
                $armed = false;

                throw new RuntimeException('injected: failure between the assignment and its throughs');
            }
        });

        expect(fn () => $this->agent->assignRole('accountant', $this->team, ['viewer']))
            ->toThrow(RuntimeException::class, 'injected');

        Permixion::flushEffectivePermissions();

        expect($this->agent->getAssignedRoleNames($this->team))->toBe([])
            ->and($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeFalse()
            ->and($this->agent->hasPermissionTo('ledger.read', $this->team))->toBeFalse();
    });

    it('a failure after the throughs are deleted leaves the assignment capped', function (): void {
        $this->agent->assignRole('accountant', $this->team, ['viewer']);

        $armed = true;
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && str_starts_with($query->sql, 'delete from "permixion_assignment_throughs"')) {
                $armed = false;

                throw new RuntimeException('injected: failure between deleting the throughs and the assignment');
            }
        });

        expect(fn () => $this->agent->removeRole('accountant', $this->team))
            ->toThrow(RuntimeException::class, 'injected');

        Permixion::flushEffectivePermissions();

        expect($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeFalse()
            ->and($this->agent->hasPermissionTo('ledger.read', $this->team))->toBeTrue();
    });

    it('a removal committed while permissions are being read never reads as uncapped', function (): void {
        $this->agent->assignRole('accountant', $this->team, ['viewer']);
        Permixion::flushEffectivePermissions();

        // Another process removes the assignment (throughs and row together, as
        // one commit) just after this one has read the holder's assignments.
        $armed = true;
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && str_contains($query->sql, 'as "assignment_id"')) {
                $armed = false;

                $ids = DB::table('taggables')->where('taggable_id', $this->agent->getKey())->pluck('id');
                DB::table('permixion_assignment_throughs')->whereIn('assignment_id', $ids)->delete();
                DB::table('taggables')->whereIn('id', $ids)->delete();
            }
        });

        expect($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeFalse();
    });
});

describe('the memo in a long-lived process', function (): void {
    /*
    | Outside a request or a queued job nothing resets the scoped memo, so a
    | change another process makes is not seen until the unit of work flushes it
    | — or until a result outlives permixion.memo.ttl seconds.
    */

    beforeEach(function (): void {
        $this->agent->assignRole('accountant', $this->team);

        // Another process removes the role, behind this one's back.
        $this->removeBehindTheBack = function (): void {
            DB::table('taggables')->where('taggable_id', $this->agent->getKey())->delete();
        };
    });

    it('by default keeps a result for the unit of work, until flushed', function (): void {
        expect(config('permixion.memo.ttl'))->toBeNull()
            ->and($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeTrue();

        ($this->removeBehindTheBack)();
        $this->travel(1)->hours();

        expect($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeTrue();

        Permixion::flushEffectivePermissions();

        expect($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeFalse();
    });

    it('forgetting the scoped instances starts the next unit of work empty', function (): void {
        expect($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeTrue();

        ($this->removeBehindTheBack)();
        app()->forgetScopedInstances();

        expect($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeFalse();
    });

    it('with a ttl, recomputes a result older than it', function (): void {
        config()->set('permixion.memo.ttl', 30);

        expect($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeTrue();

        ($this->removeBehindTheBack)();
        $this->travel(29)->seconds();

        expect($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeTrue();

        $this->travel(2)->seconds();

        expect($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeFalse();
    });

    it('with a ttl of 0, never memoises', function (): void {
        config()->set('permixion.memo.ttl', 0);

        expect($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeTrue();

        ($this->removeBehindTheBack)();

        expect($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeFalse();
    });
});
