<?php

declare(strict_types=1);

namespace RobinsonRyan\Permixion\Exceptions;

use Exception;

class PermissionAlreadyExists extends Exception
{
    public function __construct(string $permissionName)
    {
        parent::__construct("Permission '{$permissionName}' already exists.");
    }
}
