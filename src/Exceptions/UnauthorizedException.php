<?php

namespace RobinsonRyan\Permixion\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

class UnauthorizedException extends HttpException
{
    protected array $requiredRoles = [];

    protected array $requiredPermissions = [];

    public static function notLoggedIn(): static
    {
        return new static(403, 'User is not logged in.');
    }

    public static function missingTrait(): static
    {
        return new static(403, 'User model must use HasRoles trait.');
    }

    public static function forRoles(array $roles): static
    {
        $exception = new static(403, 'User does not have the required roles.');
        $exception->requiredRoles = $roles;

        return $exception;
    }

    public static function forPermissions(array $permissions): static
    {
        $exception = new static(403, 'User does not have the required permissions.');
        $exception->requiredPermissions = $permissions;

        return $exception;
    }

    public static function forRolesOrPermissions(array $rolesOrPermissions): static
    {
        $exception = new static(403, 'User does not have any of the required roles or permissions.');
        $exception->requiredRoles = $rolesOrPermissions;
        $exception->requiredPermissions = $rolesOrPermissions;

        return $exception;
    }

    public function getRequiredRoles(): array
    {
        return $this->requiredRoles;
    }

    public function getRequiredPermissions(): array
    {
        return $this->requiredPermissions;
    }
}
