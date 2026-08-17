<?php

declare(strict_types=1);

namespace RobinsonRyan\Permixion\Tests\Fixtures\Seeders;

use Illuminate\Database\Seeder;
use RobinsonRyan\Permixion\Facades\Permixion;

/**
 * Shaped after the permission seeder a consuming application actually ships:
 * clear the cache, walk a fixed list, guard each create with an existence check
 * so a re-run is a no-op rather than a strict-mode exception.
 *
 * The names include a dotted pair (`account.view` / `accountview`) that
 * `Str::slug` flattens onto one value — the collision that moved this package to
 * storing the name verbatim in `slug`.
 */
final class PermissionSeeder extends Seeder
{
    /** @var list<string> */
    public const PERMISSIONS = [
        'work_order.view',
        'work_order.assign',
        'work_order.close',
        'account.view',
        'accountview',
        'settings.update',
    ];

    public function run(): void
    {
        Permixion::clearCache();

        foreach (self::PERMISSIONS as $permission) {
            if (! Permixion::permissionExists($permission)) {
                Permixion::createPermission($permission);
            }
        }
    }
}
