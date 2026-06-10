<?php

declare(strict_types=1);

namespace RobinsonRyan\Permixion\Exceptions;

use Exception;

class RoleDoesNotExist extends Exception
{
    public function __construct(string $roleName)
    {
        parent::__construct("Role '{$roleName}' does not exist.");
    }
}
