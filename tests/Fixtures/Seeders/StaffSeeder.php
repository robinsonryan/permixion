<?php

declare(strict_types=1);

namespace RobinsonRyan\Permixion\Tests\Fixtures\Seeders;

use Illuminate\Database\Seeder;
use RobinsonRyan\Permixion\Tests\Fixtures\User;

/**
 * The half of seeding that creates users and grants them roles.
 *
 * One of the three is created with `createQuietly()` on purpose. Taxon 0.5.0
 * held a tag assignment on an unsaved model and flushed it from a `saved`
 * listener, which a quiet save suppresses — so a quietly-seeded user could end
 * up with no grant and no error. Taxon 0.5.1 moved the flush into `save()`
 * itself. This package assigns through the `tags()` relation instead of a tag
 * attribute, so it should never have been exposed; the quiet user is here to
 * keep proving that.
 */
final class StaffSeeder extends Seeder
{
    /** @var array<string, string> */
    public const STAFF = [
        'manager@example.test' => 'shop_manager',
        'tech@example.test' => 'technician',
        'desk@example.test' => 'front_desk',
    ];

    public function run(): void
    {
        foreach (self::STAFF as $email => $role) {
            $user = $email === 'desk@example.test'
                ? User::createQuietly(['name' => $email, 'email' => $email])
                : User::create(['name' => $email, 'email' => $email]);

            $user->assignRole($role);
        }
    }
}
