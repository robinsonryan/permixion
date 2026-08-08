<?php

declare(strict_types=1);

namespace RobinsonRyan\Permixion\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $title
 * @property int $author_id
 */
class Post extends Model
{
    protected $guarded = [];
}
