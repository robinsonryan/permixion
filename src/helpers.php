<?php

use RobinsonRyan\Permixion\Models\Permission;
use RobinsonRyan\Permixion\Models\Role;

if (! function_exists('role')) {
    function role(string $name): ?Role
    {
        return app('permixion')->findRole($name);
    }
}

if (! function_exists('permission')) {
    function permission(string $name): ?Permission
    {
        return app('permixion')->findPermission($name);
    }
}
