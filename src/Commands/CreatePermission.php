<?php

namespace RobinsonRyan\Permixion\Commands;

use Illuminate\Console\Command;

class CreatePermission extends Command
{
    protected $signature = 'permixion:create-permission {name : The name of the permission}';

    protected $description = 'Create a new permission';

    public function handle(): int
    {
        $name = $this->argument('name');

        try {
            $permission = app('permixion')->createPermission($name);

            $this->info("Permission '{$permission->name}' created successfully.");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
