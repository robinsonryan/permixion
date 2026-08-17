<?php

declare(strict_types=1);

namespace RobinsonRyan\Permixion\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use RobinsonRyan\Permixion\Traits\HasRoles;
use RobinsonRyan\Taxon\HasTags;

/**
 * A consumer user keyed the way the doctrine says one should be keyed: a `uuid`
 * column with a `uuidv7()` default, nothing generated in PHP.
 *
 * `$incrementing = true` beside a UUID is the point, not a typo — in Eloquent it
 * means "the database assigns the key on insert", which is what a column default
 * does. It is what compiles the INSERT with `returning "id"` so the generated key
 * is hydrated back onto the model.
 *
 * Used only by tests/Postgres, where roles and permissions are exercised against
 * Taxon's uuid7 mode on a real PostgreSQL server.
 */
class Uuid7User extends Authenticatable
{
    use HasRoles;
    use HasTags;

    public $incrementing = true;

    protected $table = 'uuid7_users';

    protected $keyType = 'string';

    protected $guarded = [];
}
