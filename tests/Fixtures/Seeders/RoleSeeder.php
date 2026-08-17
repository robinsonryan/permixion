<?php

declare(strict_types=1);

namespace RobinsonRyan\Permixion\Tests\Fixtures\Seeders;

use Illuminate\Database\Seeder;
use RobinsonRyan\Permixion\Facades\Permixion;

/**
 * Shaped after the role seeder a consuming application actually ships: find or
 * create each role, then `syncPermissions()` the whole set so the seeder is the
 * source of truth on a re-run.
 */
final class RoleSeeder extends Seeder
{
    /** @var array<string, list<string>> */
    public const ROLES = [
        'shop_manager' => [
            'work_order.view',
            'work_order.assign',
            'work_order.close',
            'settings.update',
        ],
        'technician' => [
            'work_order.view',
            'work_order.close',
        ],
        'front_desk' => [
            'account.view',
        ],
    ];

    public function run(): void
    {
        Permixion::clearCache();

        foreach (self::ROLES as $name => $permissions) {
            $role = Permixion::roleExists($name)
                ? Permixion::findRoleOrFail($name)
                : Permixion::createRole($name);

            $role->syncPermissions($permissions);
        }
    }
}
