<?php

use RobinsonRyan\Permixion\Facades\Permixion;
use RobinsonRyan\Permixion\Tests\Fixtures\User;

/**
 * Regression tests for the slug→name identity fix.
 *
 * Previously, lookups went through Str::slug, which mangled
 * delimiter-bearing identifiers ('account.view' → 'accountview',
 * 'work_order.assign' → 'work-orderassign'). These tests pin the
 * verbatim-name behavior in place.
 */
beforeEach(function (): void {
    $this->user = User::create([
        'name' => 'Test User',
        'email' => 'name-identity@example.com',
    ]);
});

test('createPermission preserves dotted names verbatim', function (): void {
    $permission = Permixion::createPermission('account.view');

    expect($permission->name)->toBe('account.view');
});

test('findPermission round-trips a dotted name', function (): void {
    Permixion::createPermission('work_order.assign');

    $found = Permixion::findPermission('work_order.assign');

    expect($found)->not->toBeNull()
        ->and($found->name)->toBe('work_order.assign');
});

test('two permissions with similar slugs are distinct', function (): void {
    Permixion::createPermission('account.view');
    Permixion::createPermission('accountview');
    Permixion::createPermission('account-view');

    expect(Permixion::permissionExists('account.view'))->toBeTrue()
        ->and(Permixion::permissionExists('accountview'))->toBeTrue()
        ->and(Permixion::permissionExists('account-view'))->toBeTrue()
        ->and(count(Permixion::getAllPermissions()))->toBe(3);
});

test('role.givePermissionTo binds dotted permissions correctly', function (): void {
    Permixion::createPermission('qc_checklist.complete');
    Permixion::createPermission('work_order.assign');

    $role = Permixion::createRole('technician');
    $role->givePermissionTo(['qc_checklist.complete', 'work_order.assign']);

    expect($role->getPermissionNames())
        ->toContain('qc_checklist.complete')
        ->toContain('work_order.assign');
});

test('hasPermissionTo works with dotted permission names', function (): void {
    Permixion::createPermission('account.view');
    Permixion::createPermission('account.delete');

    Permixion::createRole('viewer', ['account.view']);

    $this->user->assignRole('viewer');

    expect($this->user->hasPermissionTo('account.view'))->toBeTrue()
        ->and($this->user->hasPermissionTo('account.delete'))->toBeFalse();
});

test('wildcard matches dotted permission names', function (): void {
    Permixion::createPermission('reports.view_sales');
    Permixion::createPermission('reports.view_inventory');
    Permixion::createPermission('reports.view_financial');
    Permixion::createPermission('reports.export');

    Permixion::createPermission('reports.*');
    Permixion::createRole('reports_reader', ['reports.*']);

    $this->user->assignRole('reports_reader');

    expect($this->user->hasPermissionTo('reports.view_sales'))->toBeTrue()
        ->and($this->user->hasPermissionTo('reports.view_inventory'))->toBeTrue()
        ->and($this->user->hasPermissionTo('reports.view_financial'))->toBeTrue()
        ->and($this->user->hasPermissionTo('reports.export'))->toBeTrue();
});

test('createRole preserves verbatim names', function (): void {
    Permixion::createRole('shop_manager');

    $role = Permixion::findRole('shop_manager');

    expect($role)->not->toBeNull()
        ->and($role->name)->toBe('shop_manager');
});

test('user role names round-trip verbatim', function (): void {
    Permixion::createRole('shop_manager');
    Permixion::createRole('sales_rep');

    $this->user->assignRole(['shop_manager', 'sales_rep']);

    expect($this->user->getRoleNames())
        ->toContain('shop_manager')
        ->toContain('sales_rep');
});

test('direct permission with dotted name is checkable', function (): void {
    Permixion::createPermission('settings.update');

    $this->user->givePermissionTo('settings.update');

    expect($this->user->hasPermissionTo('settings.update'))->toBeTrue();
});

test('role syncPermissions replaces dotted permissions cleanly', function (): void {
    Permixion::createPermission('quotes.view');
    Permixion::createPermission('quotes.create');
    Permixion::createPermission('quotes.delete');

    $role = Permixion::createRole('sales');
    $role->givePermissionTo(['quotes.view', 'quotes.create']);

    $role->syncPermissions(['quotes.view', 'quotes.delete']);

    expect($role->getPermissionNames())
        ->toContain('quotes.view')
        ->toContain('quotes.delete')
        ->not->toContain('quotes.create');
});
