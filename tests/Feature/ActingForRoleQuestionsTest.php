<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use RobinsonRyan\Permixion\Exceptions\UnauthorizedException;
use RobinsonRyan\Permixion\Facades\Permixion;
use RobinsonRyan\Permixion\GlobalScope;
use RobinsonRyan\Permixion\Middleware\RoleMiddleware;
use RobinsonRyan\Permixion\Middleware\RoleOrPermissionMiddleware;
use RobinsonRyan\Permixion\Models\Permission;
use RobinsonRyan\Permixion\Models\Role;
use RobinsonRyan\Permixion\Tests\Fixtures\Agent;
use RobinsonRyan\Permixion\Tests\Fixtures\ApiKey;
use RobinsonRyan\Permixion\Tests\Fixtures\ConnectedApp;
use RobinsonRyan\Permixion\Tests\Fixtures\GateUser;
use RobinsonRyan\Permixion\Tests\Fixtures\Team;

/*
|--------------------------------------------------------------------------
| Acting for — role questions
|--------------------------------------------------------------------------
| A role is IN FORCE only when the holder is active and the role survives its
| throughs whole: every permission of the role is still granted after its
| assignment's throughs and the holder's withCeiling() ceilings cut it. A role
| with no permissions survives only when nothing caps it. The super-admin role
| is in force only when the holder's effective permissions are unlimited.
|
| Every role question — hasRole and its family, the role and
| role_or_permission middleware, the @role directives — asks "in force", and
| getAllPermissions() lists effective permissions. getAssignedRoleNames() is
| the raw list of what is stored.
*/

beforeEach(function (): void {
    foreach (['ledger.read', 'ledger.draft', 'ledger.post', 'ledger.close'] as $permission) {
        Permixion::createPermission($permission);
    }

    Permixion::createRole('viewer', ['ledger.read']);
    Permixion::createRole('bookkeeper', ['ledger.read', 'ledger.draft', 'ledger.post']);
    Permixion::createRole('accountant', ['ledger.read', 'ledger.draft', 'ledger.post', 'ledger.close']);
    Permixion::createRole('owner-marker');
    Permixion::createRole('super-admin');

    $this->team = Team::create(['name' => 'Acme']);
    $this->otherTeam = Team::create(['name' => 'Globex']);

    $this->owner = GateUser::create(['name' => 'Owner', 'email' => 'owner@example.com']);
    $this->agent = Agent::create(['name' => 'Agent']);

    config()->set('permixion.teams.enabled', true);
    config()->set('permixion.teams.resolver', 'callback');
    config()->set('permixion.teams.callback', fn () => $this->team);
});

/** @return array<int, string> */
function roleQuestionNames(iterable $names): array
{
    $names = collect($names)->values()->all();
    sort($names);

    return $names;
}

describe('hasRole', function (): void {
    it('is true for an uncapped assignment', function (): void {
        $this->agent->assignRole('accountant', $this->team);

        expect($this->agent->hasRole('accountant', $this->team))->toBeTrue();
    });

    it('is false for a role a ceiling role cuts', function (): void {
        $this->agent->assignRole('accountant', $this->team, ['viewer']);

        expect($this->agent->hasRole('accountant', $this->team))->toBeFalse();
    });

    it('is true for a role its ceiling leaves whole', function (): void {
        $this->agent->assignRole('viewer', $this->team, ['accountant']);

        expect($this->agent->hasRole('viewer', $this->team))->toBeTrue();
    });

    it('is false when the through holder may do less than the role', function (): void {
        $this->owner->assignRole('viewer', $this->team);
        $this->agent->assignRole('accountant', $this->team, [$this->owner]);

        expect($this->agent->hasRole('accountant', $this->team))->toBeFalse();
    });

    it('is true when the through holder may do everything the role does', function (): void {
        $this->owner->assignRole('accountant', $this->team);
        $this->agent->assignRole('accountant', $this->team, [$this->owner]);

        expect($this->agent->hasRole('accountant', $this->team))->toBeTrue();
    });

    it('judges the role by its own throughs, not by what other assignments add back', function (): void {
        // Effective permissions cover accountant (bookkeeper + a direct close),
        // but the accountant assignment itself is cut to viewer.
        $this->agent->assignRole('accountant', $this->team, ['viewer']);
        $this->agent->assignRole('bookkeeper', $this->team);
        $this->agent->givePermissionTo('ledger.close', $this->team);

        expect($this->agent->hasPermissionTo('ledger.close', $this->team))->toBeTrue()
            ->and($this->agent->hasRole('accountant', $this->team))->toBeFalse()
            ->and($this->agent->hasRole('bookkeeper', $this->team))->toBeTrue();
    });

    it('is false for a revoked holder, and true again once re-activated', function (): void {
        $key = ApiKey::create(['name' => 'Key']);
        $key->assignRole('accountant', $this->team);

        $key->update(['revoked_at' => now()]);

        expect($key->hasRole('accountant', $this->team))->toBeFalse();

        $key->update(['revoked_at' => null]);

        expect($key->hasRole('accountant', $this->team))->toBeTrue();
    });

    it('is false for an active holder acting through a revoked one', function (): void {
        $app = ConnectedApp::create(['name' => 'App']);
        $key = ApiKey::create(['name' => 'Key']);
        $app->assignRole('accountant', $this->team);
        $key->assignRole('accountant', $this->team, [$app]);

        expect($key->hasRole('accountant', $this->team))->toBeTrue();

        $app->update(['revoked_at' => now()]);
        Permixion::flushEffectivePermissions();

        expect($key->hasRole('accountant', $this->team))->toBeFalse();
    });

    it('is false under a withCeiling() that cuts the role, true under one that does not', function (): void {
        $this->agent->assignRole('accountant', $this->team);

        expect($this->agent->withCeiling('viewer')->hasRole('accountant', $this->team))->toBeFalse()
            ->and($this->agent->withCeiling('accountant')->hasRole('accountant', $this->team))->toBeTrue();
    });

    it('a role with no permissions is in force uncapped, and not once anything caps it', function (): void {
        $other = Agent::create(['name' => 'Other']);
        $this->agent->assignRole('owner-marker', $this->team);
        $other->assignRole('owner-marker', $this->team, [$this->agent]);

        expect($this->agent->hasRole('owner-marker', $this->team))->toBeTrue()
            ->and($other->hasRole('owner-marker', $this->team))->toBeFalse()
            ->and($this->agent->withCeiling('viewer')->hasRole('owner-marker', $this->team))->toBeFalse();
    });

    it('a cycle, a deleted through and a too-deep chain leave no role in force', function (): void {
        $other = Agent::create(['name' => 'Other']);
        $this->agent->assignRole('viewer', $this->team, [$other]);
        $other->assignRole('viewer', $this->team, [$this->agent]);

        $gone = Agent::create(['name' => 'Gone']);
        $gone->assignRole('accountant', $this->team);
        $orphan = Agent::create(['name' => 'Orphan']);
        $orphan->assignRole('viewer', $this->team, [$gone]);
        $gone->delete();
        Permixion::flushEffectivePermissions();

        config()->set('permixion.through.max_depth', 1);
        $deep = Agent::create(['name' => 'Deep']);
        $mid = Agent::create(['name' => 'Mid']);
        $this->owner->assignRole('accountant', $this->team);
        $mid->assignRole('viewer', $this->team, [$this->owner]);
        $deep->assignRole('viewer', $this->team, [$mid]);

        expect($this->agent->hasRole('viewer', $this->team))->toBeFalse()
            ->and($orphan->hasRole('viewer', $this->team))->toBeFalse()
            ->and($mid->hasRole('viewer', $this->team))->toBeTrue()
            ->and($deep->hasRole('viewer', $this->team))->toBeFalse();
    });

    it('answers in the scope asked, never another', function (): void {
        $this->agent->assignRole('accountant', $this->otherTeam);

        expect($this->agent->hasRole('accountant', $this->team))->toBeFalse()
            ->and($this->agent->hasRole('accountant', $this->otherTeam))->toBeTrue();
    });
});

describe('super-admin as a role', function (): void {
    it('is in force when held uncapped by an active holder', function (): void {
        $this->agent->assignRole('super-admin', $this->team);

        expect($this->agent->hasRole('super-admin', $this->team))->toBeTrue();
    });

    it('is not in force when held through a capped holder', function (): void {
        $this->owner->assignRole('viewer', $this->team);
        $this->agent->assignRole('super-admin', $this->team, [$this->owner]);

        expect($this->agent->hasRole('super-admin', $this->team))->toBeFalse();
    });

    it('is not in force under a ceiling, nor for an inactive holder', function (): void {
        $this->agent->assignRole('super-admin', $this->team);

        expect($this->agent->withCeiling('viewer')->hasRole('super-admin', $this->team))->toBeFalse();

        $this->agent->update(['revoked_at' => now()]);

        expect($this->agent->hasRole('super-admin', $this->team))->toBeFalse();
    });
});

describe('the rest of the hasRole family', function (): void {
    beforeEach(function (): void {
        $this->agent->assignRole('accountant', $this->team, ['viewer']);
        $this->agent->assignRole('viewer', $this->team);
    });

    it('hasAnyRole and hasAllRoles count only roles in force', function (): void {
        expect($this->agent->hasAnyRole(['accountant'], $this->team))->toBeFalse()
            ->and($this->agent->hasAnyRole(['accountant', 'viewer'], $this->team))->toBeTrue()
            ->and($this->agent->hasAllRoles(['accountant', 'viewer'], $this->team))->toBeFalse()
            ->and($this->agent->hasAllRoles(['viewer'], $this->team))->toBeTrue();
    });

    it('hasExactRoles, getRoleNames and getRoles list only roles in force', function (): void {
        expect($this->agent->hasExactRoles(['viewer'], $this->team))->toBeTrue()
            ->and($this->agent->hasExactRoles(['accountant', 'viewer'], $this->team))->toBeFalse()
            ->and($this->agent->getRoleNames($this->team))->toBe(['viewer'])
            ->and($this->agent->getRoles($this->team)->map(fn (Role $role): string => $role->name)->all())->toBe(['viewer']);
    });

    it('getAssignedRoleNames lists what is stored, in force or not', function (): void {
        expect(roleQuestionNames($this->agent->getAssignedRoleNames($this->team)))->toBe(['accountant', 'viewer']);
    });

    it('hasRoleAnywhere counts a role only where it is in force', function (): void {
        $key = ApiKey::create(['name' => 'Key']);
        $key->assignRole('accountant', $this->team, ['viewer']);

        expect($key->hasRoleAnywhere('accountant'))->toBeFalse();

        $key->assignRole('accountant', $this->otherTeam);

        expect($key->hasRoleAnywhere('accountant'))->toBeTrue();

        $key->update(['revoked_at' => now()]);

        expect($key->hasRoleAnywhere('accountant'))->toBeFalse();
    });

    it('hasRoleAnywhere reads a global row in force', function (): void {
        $key = ApiKey::create(['name' => 'Key']);
        $key->assignRole('accountant', GlobalScope::instance());

        expect($key->hasRoleAnywhere('accountant'))->toBeTrue();
    });
});

describe('getAllPermissions', function (): void {
    it('lists effective permissions, not every permission of every assigned role', function (): void {
        $this->agent->assignRole('accountant', $this->team, ['viewer']);

        expect(roleQuestionNames($this->agent->getAllPermissions($this->team)->map(fn (Permission $p): string => $p->name)))
            ->toBe(['ledger.read']);
    });

    it('is empty for an inactive holder', function (): void {
        $this->agent->assignRole('accountant', $this->team);
        $this->agent->update(['revoked_at' => now()]);

        expect($this->agent->getAllPermissions($this->team))->toBeEmpty();
    });

    it('still lists every permission of an uncapped role and the direct ones', function (): void {
        $this->agent->assignRole('bookkeeper', $this->team);
        $this->agent->givePermissionTo('ledger.close', $this->team);

        expect(roleQuestionNames($this->agent->getAllPermissions($this->team)->map(fn (Permission $p): string => $p->name)))
            ->toBe(['ledger.close', 'ledger.draft', 'ledger.post', 'ledger.read']);
    });
});

describe('the role middleware', function (): void {
    beforeEach(function (): void {
        Route::middleware('role:accountant')->get('/accountants', fn (): string => 'ok');
        Route::middleware('role_or_permission:accountant')->get('/accountants-or', fn (): string => 'ok');
    });

    it('refuses a capped user the role, and role_or_permission does too', function (): void {
        $this->owner->assignRole('accountant', $this->team, ['viewer']);

        $this->actingAs($this->owner)->get('/accountants')->assertForbidden();
        $this->actingAs($this->owner)->get('/accountants-or')->assertForbidden();
    });

    it('admits the same user holding the role uncapped', function (): void {
        $this->owner->assignRole('accountant', $this->team);

        $this->actingAs($this->owner)->get('/accountants')->assertOk();
        $this->actingAs($this->owner)->get('/accountants-or')->assertOk();
    });

    it('refuses a revoked key in both middleware', function (): void {
        $key = ApiKey::create(['name' => 'Key', 'revoked_at' => now()]);
        $key->assignRole('accountant', $this->team);

        $request = Request::create('/accountants');
        $request->setUserResolver(fn () => $key);

        expect(fn (): mixed => (new RoleMiddleware)->handle($request, fn (): string => 'ok', 'accountant'))
            ->toThrow(UnauthorizedException::class)
            ->and(fn (): mixed => (new RoleOrPermissionMiddleware)->handle($request, fn (): string => 'ok', 'accountant'))
            ->toThrow(UnauthorizedException::class);

        $key->update(['revoked_at' => null]);

        expect((new RoleMiddleware)->handle($request, fn (): string => 'ok', 'accountant'))->toBe('ok');
    });
});

describe('the @role directives', function (): void {
    it('do not render for a capped user, and @unlessrole does', function (): void {
        $this->owner->assignRole('accountant', $this->team, ['viewer']);
        $this->actingAs($this->owner);

        expect(Blade::check('role', 'accountant'))->toBeFalse()
            ->and(Blade::check('hasrole', 'accountant'))->toBeFalse()
            ->and(Blade::check('hasanyrole', ['accountant']))->toBeFalse()
            ->and(Blade::check('hasallroles', ['accountant']))->toBeFalse()
            ->and(Blade::check('unlessrole', 'accountant'))->toBeTrue();
    });

    it('render for the same user holding the role uncapped', function (): void {
        $this->owner->assignRole('accountant', $this->team);
        $this->actingAs($this->owner);

        expect(Blade::check('role', 'accountant'))->toBeTrue()
            ->and(Blade::check('unlessrole', 'accountant'))->toBeFalse();
    });
});
