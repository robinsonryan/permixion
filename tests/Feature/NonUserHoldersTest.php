<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use RobinsonRyan\Permixion\Facades\Permixion;
use RobinsonRyan\Permixion\Tests\Fixtures\Agent;
use RobinsonRyan\Permixion\Tests\Fixtures\ApiKey;
use RobinsonRyan\Permixion\Tests\Fixtures\ConnectedApp;
use RobinsonRyan\Permixion\Tests\Fixtures\Team;

/*
|--------------------------------------------------------------------------
| Holders that are not users
|--------------------------------------------------------------------------
| An Agent, an API key, an app: any model with HasRoles and HasTags that
| satisfies the framework's Authorizable contract. They are checked through
| Gate::forUser($holder), and the Gate::before hook takes their scope from the
| teams resolver — `callback` mode, for apps whose scope comes from tenancy
| rather than a route parameter.
*/

beforeEach(function (): void {
    Permixion::createPermission('ledger.read');
    Permixion::createPermission('ledger.post');
    Permixion::createRole('viewer', ['ledger.read']);
    Permixion::createRole('bookkeeper', ['ledger.read', 'ledger.post']);

    $this->company = Team::create(['name' => 'Acme']);
    $this->otherCompany = Team::create(['name' => 'Globex']);

    $this->current = $this->company;
    config()->set('permixion.teams.enabled', true);
    config()->set('permixion.teams.resolver', 'callback');
    config()->set('permixion.teams.callback', fn () => $this->current);
});

it('answers Gate::forUser for an Agent from its permissions in the resolved scope', function (): void {
    $agent = Agent::create(['name' => 'Agent']);
    $agent->assignRole('viewer');

    expect(Permixion::resolveCurrentScope())->toBe($this->company)
        ->and(Gate::forUser($agent)->allows('ledger.read'))->toBeTrue()
        ->and(Gate::forUser($agent)->allows('ledger.post'))->toBeFalse();
});

it('assigns into the callback-resolved scope, and grants nothing in another', function (): void {
    $agent = Agent::create(['name' => 'Agent']);
    $agent->assignRole('bookkeeper');

    $this->current = $this->otherCompany;

    expect(Gate::forUser($agent)->allows('ledger.read'))->toBeFalse()
        ->and($agent->hasPermissionTo('ledger.read', $this->company))->toBeTrue();
});

it('answers Gate::forUser for an API key that mixes in the framework Authorizable trait', function (): void {
    $app = ConnectedApp::create(['name' => 'App']);
    $app->assignRole('viewer');

    $key = ApiKey::create(['name' => 'Key']);
    $key->assignRole('bookkeeper', null, [$app]);

    expect(Gate::forUser($key)->allows('ledger.read'))->toBeTrue()
        ->and(Gate::forUser($key)->allows('ledger.post'))->toBeFalse()
        ->and($key->can('ledger.read'))->toBeTrue()
        ->and($key->can('ledger.post'))->toBeFalse();
});

it('refuses a revoked key through the Gate', function (): void {
    $key = ApiKey::create(['name' => 'Key', 'revoked_at' => now()]);
    $key->assignRole('bookkeeper');

    expect(Gate::forUser($key)->allows('ledger.read'))->toBeFalse()
        ->and($key->can('ledger.read'))->toBeFalse();
});

it('refuses through the Gate when the scope resolves to nothing', function (): void {
    $agent = Agent::create(['name' => 'Agent']);
    $agent->assignRole('bookkeeper');

    $this->current = null;

    expect(Gate::forUser($agent)->allows('ledger.read'))->toBeFalse();
});
