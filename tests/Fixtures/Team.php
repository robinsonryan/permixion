<?php

namespace RobinsonRyan\Permixion\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RobinsonRyan\Taxon\Concerns\CanScopeTags;
use RobinsonRyan\Taxon\Contracts\Scope;

class Team extends Model implements Scope
{
    use CanScopeTags;

    protected $guarded = [];
}
