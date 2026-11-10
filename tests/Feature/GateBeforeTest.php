<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use RobinsonRyan\Permixion\Contracts\HasPermissions;
use RobinsonRyan\Permixion\Facades\Permixion;
use RobinsonRyan\Permixion\Tests\Fixtures\GateUser;
use RobinsonRyan\Permixion\Tests\Fixtures\Post;
use RobinsonRyan\Permixion\Tests\Fixtures\PostPolicy;
use RobinsonRyan\Permixion\Tests\Fixtures\Team;
use RobinsonRyan\Permixion\Tests\Fixtures\User;

beforeEach(function (): void {
    Gate::policy(Post::class, PostPolicy::class);

    Permixion::createPermission('update');
    Permixion::createPermission('posts.publish');
    Permixion::createRole('editor', ['update']);
    Permixion::createRole('super-admin');

    $this->author = GateUser::create(['name' => 'Author', 'email' => 'author@example.com']);
    $this->other = GateUser::create(['name' => 'Other', 'email' => 'other@example.com']);

    $this->post = Post::create(['title' => 'Hello', 'author_id' => $this->author->id]);
});

/*
|--------------------------------------------------------------------------
| The opt-in guard
|--------------------------------------------------------------------------
| The hook requires `implements HasPermissions`. The HasRoles trait alone does
| NOT satisfy it. This is deliberate: declaring the contract is how a consumer
| consents to permixion outranking its policies. Both shapes are pinned so the
| guard cannot be "tidied" into a capability check without a failing test.
*/

test('a trait-only user does not trigger the hook, even holding the permission', function (): void {
    $traitOnly = User::create(['name' => 'Trait Only', 'email' => 'trait@example.com']);
    $traitOnly->assignRole('editor');

    expect($traitOnly)->not->toBeInstanceOf(HasPermissions::class)
        ->and($traitOnly->hasPermissionTo('update'))->toBeTrue();

    // Holds the permission, but never reaches the short-circuit. Policy decides.
    expect(Gate::forUser($traitOnly)->allows('update', $this->post))->toBeFalse();
});

test('a contract-declaring user does trigger the hook', function (): void {
    $this->other->assignRole('editor');

    expect(Gate::forUser($this->other)->allows('update', $this->post))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Structural guarantees — what makes the hook safe to install at all
|--------------------------------------------------------------------------
*/

test('the hook cannot deny: it never turns a policy grant into a refusal', function (): void {
    // The author holds no roles at all, so the hook returns null. The policy's
    // grant must survive. A Gate::before returning false would break every
    // unrelated gate in the consuming app; the ?true return type forecloses it.
    expect(Gate::forUser($this->author)->allows('update', $this->post))->toBeTrue();
});

test('an ability permixion has never heard of falls through instead of throwing', function (): void {
    // strict mode is on (TestCase pins it). The READ path must still not throw —
    // the permixion.strict throws are confined to the write paths.
    expect(Gate::forUser($this->other)->allows('nonexistent-ability-xyz', $this->post))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Super admin
|--------------------------------------------------------------------------
*/

test('super admin is granted every ability, including undefined ones', function (): void {
    $this->other->assignRole('super-admin');

    expect(Gate::forUser($this->other)->allows('update', $this->post))->toBeTrue()
        ->and(Gate::forUser($this->other)->allows('anything-at-all', $this->post))->toBeTrue()
        ->and(Gate::forUser($this->other)->allows('viewAny', Post::class))->toBeTrue();
});

test('super admin is not consulted when the feature is disabled', function (): void {
    config()->set('permixion.super_admin.enabled', false);

    $this->other->assignRole('super-admin');

    expect(Gate::forUser($this->other)->allows('update', $this->post))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The short-circuit — CHARACTERIZATION of the default, spatie-compatible mode
|--------------------------------------------------------------------------
*/

test('DEFAULT MODE: a matching permission short-circuits the policy entirely', function (): void {
    // This is what spatie/laravel-permission does too. Pinned deliberately:
    // `defer_to_policies => false` must keep doing exactly this.
    $this->other->assignRole('editor');

    // PostPolicy::update() would refuse — $other is not the author.
    expect((new PostPolicy)->update($this->other, $this->post))->toBeFalse();

    // ...but the hook answers first, without ever seeing the Post.
    expect(Gate::forUser($this->other)->allows('update', $this->post))->toBeTrue();
});

test('DEFAULT MODE: the short-circuit cannot tell two instances apart', function (): void {
    $this->other->assignRole('editor');

    $ownedByAuthor = Post::create(['title' => 'A', 'author_id' => $this->author->id]);
    $ownedByOther = Post::create(['title' => 'B', 'author_id' => $this->other->id]);

    expect(Gate::forUser($this->other)->allows('update', $ownedByAuthor))->toBeTrue()
        ->and(Gate::forUser($this->other)->allows('update', $ownedByOther))->toBeTrue();
});

test('a permission the user does not hold leaves the policy in charge', function (): void {
    $this->other->givePermissionTo('posts.publish');

    expect(Gate::forUser($this->other)->allows('update', $this->post))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| defer_to_policies => true
|--------------------------------------------------------------------------
| The opt-in that makes a per-instance policy clause un-bypassable.
*/

test('DEFERRING: a subject-bearing question is handed to the policy', function (): void {
    config()->set('permixion.gate.defer_to_policies', true);

    $this->other->assignRole('editor');

    // Still holds the permission outright...
    expect($this->other->hasPermissionTo('update'))->toBeTrue();

    // ...but the policy now decides, and refuses: not the author.
    expect(Gate::forUser($this->other)->allows('update', $this->post))->toBeFalse();
});

test('DEFERRING: the policy still grants the author — no legitimate access lost', function (): void {
    config()->set('permixion.gate.defer_to_policies', true);

    $this->author->assignRole('editor');

    expect(Gate::forUser($this->author)->allows('update', $this->post))->toBeTrue();
});

test('DEFERRING: instances are now told apart', function (): void {
    config()->set('permixion.gate.defer_to_policies', true);

    $this->other->assignRole('editor');

    $ownedByAuthor = Post::create(['title' => 'A', 'author_id' => $this->author->id]);
    $ownedByOther = Post::create(['title' => 'B', 'author_id' => $this->other->id]);

    expect(Gate::forUser($this->other)->allows('update', $ownedByAuthor))->toBeFalse()
        ->and(Gate::forUser($this->other)->allows('update', $ownedByOther))->toBeTrue();
});

test('DEFERRING: subject-free ability checks still use permissions', function (): void {
    config()->set('permixion.gate.defer_to_policies', true);

    $this->other->givePermissionTo('posts.publish');

    // No subject, so nothing for a policy to inspect — the permission answers.
    expect(Gate::forUser($this->other)->allows('posts.publish'))->toBeTrue();
});

test('DEFERRING: super admin is still granted everything', function (): void {
    config()->set('permixion.gate.defer_to_policies', true);

    $this->other->assignRole('super-admin');

    // Deliberate god-mode: the flag narrows the permission branch, not this one.
    expect(Gate::forUser($this->other)->allows('update', $this->post))->toBeTrue()
        ->and(Gate::forUser($this->other)->allows('anything-at-all', $this->post))->toBeTrue();
});

test('DEFERRING: a class-string subject is also deferred', function (): void {
    config()->set('permixion.gate.defer_to_policies', true);

    Permixion::createPermission('viewAny');
    Permixion::createRole('viewer', ['viewAny']);
    $this->other->assignRole('viewer');

    // PostPolicy::viewAny() refuses; the permission must not override it.
    expect(Gate::forUser($this->other)->allows('viewAny', Post::class))->toBeFalse();
});

test('DEFERRING: a Scope argument is not a policy subject', function (): void {
    config()->set('permixion.gate.defer_to_policies', true);

    $team = Team::create(['name' => 'Team A']);
    $this->other->givePermissionTo('posts.publish', $team);

    // A Scope is Permixion's own concern. Deferring must not mistake it for a
    // policy subject and swallow an otherwise valid permission check.
    expect(Gate::forUser($this->other)->allows('posts.publish', [$team]))->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The scope the hook answers in (0.6.0)
|--------------------------------------------------------------------------
| A Scope named in the arguments is the scope answered; with none, the ambient
| one. Before 0.6.0 the hook always read the ambient scope, so a check that
| failed in the named scope — HasRoles::can() hands it on to the Gate — was
| re-answered, and granted, on rights held in the current team.
*/

test('the hook answers in a scope passed in the arguments', function (): void {
    config()->set('permixion.teams.enabled', true);

    $team = Team::create(['name' => 'Team A']);
    $this->other->givePermissionTo('posts.publish', $team);

    expect(Gate::forUser($this->other)->allows('posts.publish', [$team]))->toBeTrue()
        ->and(Gate::forUser($this->other)->allows('posts.publish', $team))->toBeTrue()
        ->and(Gate::forUser($this->other)->allows('posts.publish'))->toBeFalse();
});

test('a scope in the arguments is never re-answered in the ambient scope', function (): void {
    config()->set('permixion.teams.enabled', true);
    config()->set('permixion.teams.resolver', 'callback');

    Permixion::createPermission('ledger.read');
    Permixion::createPermission('ledger.close');
    Permixion::createRole('viewer', ['ledger.read']);
    Permixion::createRole('accountant', ['ledger.read', 'ledger.close']);

    $acme = Team::create(['name' => 'Acme']);
    $globex = Team::create(['name' => 'Globex']);
    config()->set('permixion.teams.callback', fn (): Team => $acme);

    $this->other->assignRole('accountant', $acme);
    $this->other->assignRole('accountant', $globex, ['viewer']);

    expect($this->other->hasPermissionTo('ledger.close', $globex))->toBeFalse()
        ->and($this->other->can('ledger.close', $globex))->toBeFalse()
        ->and($this->other->can('ledger.close', [$globex]))->toBeFalse()
        ->and(Gate::forUser($this->other)->allows('ledger.close', $globex))->toBeFalse()
        ->and(Gate::forUser($this->other)->allows('ledger.close', [$globex]))->toBeFalse()
        // The grants beside the refusals: the ambient and the named Acme.
        ->and($this->other->can('ledger.close'))->toBeTrue()
        ->and($this->other->can('ledger.close', $acme))->toBeTrue()
        ->and(Gate::forUser($this->other)->allows('ledger.close'))->toBeTrue()
        ->and($this->other->can('ledger.read', $globex))->toBeTrue();
});

test('super admin is judged in the scope passed in the arguments', function (): void {
    config()->set('permixion.teams.enabled', true);
    config()->set('permixion.teams.resolver', 'callback');

    $acme = Team::create(['name' => 'Acme']);
    $globex = Team::create(['name' => 'Globex']);
    config()->set('permixion.teams.callback', fn (): Team => $acme);

    $this->other->assignRole('super-admin', $acme);

    expect(Gate::forUser($this->other)->allows('anything.at.all'))->toBeTrue()
        ->and(Gate::forUser($this->other)->allows('anything.at.all', [$globex]))->toBeFalse()
        ->and($this->other->can('anything.at.all', $globex))->toBeFalse();
});
