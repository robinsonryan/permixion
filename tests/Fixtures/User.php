<?php

declare(strict_types=1);

namespace RobinsonRyan\Permixion\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use RobinsonRyan\Permixion\Traits\HasRoles;
use RobinsonRyan\Taxon\HasTags;

class User extends Authenticatable
{
    use HasRoles;
    use HasTags;

    protected $guarded = [];
}
