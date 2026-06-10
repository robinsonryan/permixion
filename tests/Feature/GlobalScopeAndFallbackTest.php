<?php

use RobinsonRyan\Permixion\Facades\Permixion;
use RobinsonRyan\Permixion\GlobalScope;
use RobinsonRyan\Permixion\Tests\Fixtures\Team;
use RobinsonRyan\Permixion\Tests\Fixtures\User;

beforeEach(function (): void {
    Permixion::createPermission('posts.create');
    Permixion::createPermission('posts.delete');

    Permixion::createRole('owner', ['posts.create', 'posts.delete']);
    Permixion::createRole('member', ['posts.create']);

    $this->user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
    $this->teamA = Team::create(['name' => 'Team A']);
    $this->teamB = Team::create(['name' => 'Team B']);
});

test('GlobalScope assigns an unscoped row', function (): void {
    $this->user->assignRole('owner', GlobalScope::instance());

    $this->assertDatabaseHas('taggables', [
        'taggable_id' => $this->user->id,
        'scope_type' => null,
        'scope_id' => null,
    ]);

    expect($this->user->hasRole('owner', GlobalScope::instance()))->toBeTrue()
        ->and($this->user->hasRole('owner'))->toBeTrue();
});

test('without global_fallback a global role does not match scoped checks', function (): void {
    $this->user->assignRole('owner', GlobalScope::instance());

    expect($this->user->hasRole('owner', $this->teamA))->toBeFalse()
        ->and($this->user->hasPermissionTo('posts.delete', $this->teamA))->toBeFalse();
});

test('with global_fallback a global role matches every scoped check', function (): void {
    config()->set('permixion.teams.global_fallback', true);

    $this->user->assignRole('owner', GlobalScope::instance());

    expect($this->user->hasRole('owner', $this->teamA))->toBeTrue()
        ->and($this->user->hasRole('owner', $this->teamB))->toBeTrue()
        ->and($this->user->hasPermissionTo('posts.delete', $this->teamA))->toBeTrue()
        ->and($this->user->getRoleNames($this->teamA))->toContain('owner');
});

test('global_fallback never widens the other direction', function (): void {
    config()->set('permixion.teams.global_fallback', true);

    $this->user->assignRole('member', $this->teamA);

    expect($this->user->hasRole('member', GlobalScope::instance()))->toBeFalse()
        ->and($this->user->hasRole('member'))->toBeFalse()
        ->and($this->user->hasRole('member', $this->teamB))->toBeFalse()
        ->and($this->user->hasRole('member', $this->teamA))->toBeTrue();
});

test('writes stay exact when global_fallback is enabled', function (): void {
    config()->set('permixion.teams.global_fallback', true);

    $this->user->assignRole('owner', GlobalScope::instance());
    $this->user->assignRole('member', $this->teamA);

    $this->user->syncRoles(['member'], $this->teamA);
    expect($this->user->hasRole('owner', GlobalScope::instance()))->toBeTrue();

    $this->user->removeRole('owner', $this->teamA);
    expect($this->user->hasRole('owner', GlobalScope::instance()))->toBeTrue();

    $this->user->removeRole('owner', GlobalScope::instance());
    expect($this->user->hasRole('owner', GlobalScope::instance()))->toBeFalse()
        ->and($this->user->hasRole('member', $this->teamA))->toBeTrue();
});

test('getAllPermissions under a scope includes global role permissions with fallback', function (): void {
    config()->set('permixion.teams.global_fallback', true);

    $this->user->assignRole('owner', GlobalScope::instance());
    $this->user->assignRole('member', $this->teamA);

    $names = $this->user->getAllPermissions($this->teamA)->pluck('name');

    expect($names)->toContain('posts.delete')
        ->and($names)->toContain('posts.create');
});

test('hasRoleAnywhere matches any scope and ignores context', function (): void {
    $this->user->assignRole('member', $this->teamB);

    expect($this->user->hasRoleAnywhere('member'))->toBeTrue()
        ->and($this->user->hasRoleAnywhere('owner'))->toBeFalse();

    $this->user->assignRole('owner', GlobalScope::instance());

    expect($this->user->hasRoleAnywhere('owner'))->toBeTrue();
});
