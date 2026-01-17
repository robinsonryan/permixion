<?php

namespace RobinsonRyan\Permixion\Commands;

use Illuminate\Console\Command;

class ShowPermissions extends Command
{
    protected $signature = 'permixion:show-permissions {--role= : Show permissions for a specific role}';

    protected $description = 'Show all permissions or permissions for a role';

    public function handle(): int
    {
        $roleName = $this->option('role');

        if ($roleName) {
            return $this->showRolePermissions($roleName);
        }

        return $this->showAllPermissions();
    }

    protected function showRolePermissions(string $roleName): int
    {
        $role = app('permixion')->findRole($roleName);

        if (! $role) {
            $this->error("Role '{$roleName}' not found.");

            return self::FAILURE;
        }

        $permissions = $role->getPermissionNames();

        if (empty($permissions)) {
            $this->info("Role '{$roleName}' has no permissions.");

            return self::SUCCESS;
        }

        $this->info("Permissions for role '{$roleName}':");
        $this->table(['Permission'], array_map(fn ($p) => [$p], $permissions));

        return self::SUCCESS;
    }

    protected function showAllPermissions(): int
    {
        $roles = app('permixion')->getAllRoles();
        $permissions = app('permixion')->getAllPermissions();

        $this->info('Roles:');
        $this->table(
            ['Role', 'Permissions'],
            array_map(fn ($r) => [
                $r->name,
                implode(', ', $r->getPermissionNames()),
            ], $roles)
        );

        $this->newLine();
        $this->info('All Permissions:');
        $this->table(
            ['Permission'],
            array_map(fn ($p) => [$p->name], $permissions)
        );

        return self::SUCCESS;
    }
}
