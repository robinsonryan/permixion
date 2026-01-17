<?php

use RobinsonRyan\Permixion\Facades\Permixion;
use RobinsonRyan\Permixion\Tests\Fixtures\Team;
use RobinsonRyan\Permixion\Tests\Fixtures\User;

beforeEach(function () {
    Permixion::createPermission('posts.create');
    Permixion::createPermission('posts.delete');

    Permixion::createRole('admin', ['posts.create', 'posts.delete']);
    Permixion::createRole('member', ['posts.create']);

    $this->user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
    $this->teamA = Team::create(['name' => 'Team A']);
    $this->teamB = Team::create(['name' => 'Team B']);
});

test('user can have different roles per team', function () {
    $this->user->assignRole('admin', $this->teamA);
    $this->user->assignRole('member', $this->teamB);

    expect($this->user->hasRole('admin', $this->teamA))->toBeTrue()
        ->and($this->user->hasRole('member', $this->teamA))->toBeFalse()
        ->and($this->user->hasRole('member', $this->teamB))->toBeTrue()
        ->and($this->user->hasRole('admin', $this->teamB))->toBeFalse();
});

test('user has different permissions per team', function () {
    $this->user->assignRole('admin', $this->teamA);
    $this->user->assignRole('member', $this->teamB);

    expect($this->user->hasPermissionTo('posts.delete', $this->teamA))->toBeTrue()
        ->and($this->user->hasPermissionTo('posts.delete', $this->teamB))->toBeFalse()
        ->and($this->user->hasPermissionTo('posts.create', $this->teamB))->toBeTrue();
});

test('user can remove role from specific team', function () {
    $this->user->assignRole('admin', $this->teamA);
    $this->user->assignRole('admin', $this->teamB);

    $this->user->removeRole('admin', $this->teamA);

    expect($this->user->hasRole('admin', $this->teamA))->toBeFalse()
        ->and($this->user->hasRole('admin', $this->teamB))->toBeTrue();
});
