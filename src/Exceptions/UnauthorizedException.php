<?php

declare(strict_types=1);

namespace RobinsonRyan\Permixion\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class UnauthorizedException extends HttpException
{
    /** @var array<int, string> */
    protected array $requiredRoles = [];

    /** @var array<int, string> */
    protected array $requiredPermissions = [];

    public static function notLoggedIn(): static
    {
        return new self(403, 'User is not logged in.');
    }

    public static function missingTrait(): static
    {
        return new self(403, 'User model must use HasRoles trait.');
    }

    /**
     * @param  array<int, string>  $roles
     */
    public static function forRoles(array $roles): static
    {
        $exception = new self(403, 'User does not have the required roles.');
        $exception->requiredRoles = $roles;

        return $exception;
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public static function forPermissions(array $permissions): static
    {
        $exception = new self(403, 'User does not have the required permissions.');
        $exception->requiredPermissions = $permissions;

        return $exception;
    }

    /**
     * @param  array<int, string>  $rolesOrPermissions
     */
    public static function forRolesOrPermissions(array $rolesOrPermissions): static
    {
        $exception = new self(403, 'User does not have any of the required roles or permissions.');
        $exception->requiredRoles = $rolesOrPermissions;
        $exception->requiredPermissions = $rolesOrPermissions;

        return $exception;
    }

    /**
     * @return array<int, string>
     */
    public function getRequiredRoles(): array
    {
        return $this->requiredRoles;
    }

    /**
     * @return array<int, string>
     */
    public function getRequiredPermissions(): array
    {
        return $this->requiredPermissions;
    }
}
