<?php

namespace RobinsonRyan\Permixion\Commands;

use Illuminate\Console\Command;

class CreateRole extends Command
{
    protected $signature = 'permixion:create-role
                            {name : The name of the role}
                            {--P|permissions=* : Permissions to assign to the role}';

    protected $description = 'Create a new role';

    public function handle(): int
    {
        /** @var string $name */
        $name = $this->argument('name');

        /** @var array<int, string> $permissions */
        $permissions = (array) $this->option('permissions');

        try {
            $role = app('permixion')->createRole($name, $permissions);

            $this->info("Role '{$role->name}' created successfully.");

            if (! empty($permissions)) {
                $this->info('Assigned permissions: '.implode(', ', $permissions));
            }

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
