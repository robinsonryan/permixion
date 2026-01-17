<?php

namespace RobinsonRyan\Permixion\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use RobinsonRyan\Taxon\HasTags;
use RobinsonRyan\Permixion\Traits\HasRoles;

class User extends Authenticatable
{
    use HasTags;
    use HasRoles;

    protected $guarded = [];
}
