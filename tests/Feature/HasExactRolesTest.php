<?php

declare(strict_types=1);

use RobinsonRyan\Permixion\Facades\Permixion;
use RobinsonRyan\Permixion\Tests\Fixtures\Team;
use RobinsonRyan\Permixion\Tests\Fixtures\User;

beforeEach(function (): void {
    Permixion::createRole('editor');
    Permixion::createRole('admin');
    Permixion::createRole('viewer');

    $this->user = User::create(['name' => 'Test User', 'email' => 'test@example.com']);
});

test('matches an identical set regardless of order', function (): void {
    $this->user->assignRole(['editor', 'admin']);

    expect($this->user->hasExactRoles(['editor', 'admin']))->toBeTrue()
        ->and($this->user->hasExactRoles(['admin', 'editor']))->toBeTrue();
});

test('rejects a subset', function (): void {
    $this->user->assignRole(['editor', 'admin']);

    expect($this->user->hasExactRoles(['editor']))->toBeFalse();
});

test('rejects a superset', function (): void {
    $this->user->assignRole('editor');

    expect($this->user->hasExactRoles(['editor', 'admin']))->toBeFalse();
});

test('rejects a same-sized set with different members', function (): void {
    $this->user->assignRole(['editor', 'admin']);

    expect($this->user->hasExactRoles(['editor', 'viewer']))->toBeFalse();
});

test('a user with no roles matches only the empty set', function (): void {
    expect($this->user->hasExactRoles([]))->toBeTrue()
        ->and($this->user->hasExactRoles(['editor']))->toBeFalse();
});

test('accepts Role objects as well as names', function (): void {
    $this->user->assignRole(['editor', 'admin']);

    expect($this->user->hasExactRoles([Permixion::findRole('editor'), 'admin']))->toBeTrue();
});

test('is evaluated within the given scope', function (): void {
    config()->set('permixion.teams.enabled', true);

    $teamA = Team::create(['name' => 'Team A']);
    $teamB = Team::create(['name' => 'Team B']);

    $this->user->assignRole('editor', $teamA);
    $this->user->assignRole(['admin', 'viewer'], $teamB);

    expect($this->user->hasExactRoles(['editor'], $teamA))->toBeTrue()
        ->and($this->user->hasExactRoles(['admin', 'viewer'], $teamB))->toBeTrue()
        ->and($this->user->hasExactRoles(['editor'], $teamB))->toBeFalse();
});
