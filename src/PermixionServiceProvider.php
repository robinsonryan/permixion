<?php

namespace RobinsonRyan\Permixion;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use RobinsonRyan\Permixion\Commands\CreatePermission;
use RobinsonRyan\Permixion\Commands\CreateRole;
use RobinsonRyan\Permixion\Commands\ShowPermissions;
use RobinsonRyan\Permixion\Contracts\HasPermissions;
use RobinsonRyan\Taxon\Contracts\Scope;

class PermixionServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/permixion.php',
            'permixion'
        );

        $this->app->singleton(Permixion::class);
        $this->app->alias(Permixion::class, 'permixion');
    }

    public function boot(): void
    {
        $this->publishConfig();
        $this->registerCommands();
        $this->registerMiddleware();
        $this->registerBladeDirectives();
        $this->registerGatePermissions();
    }

    protected function publishConfig(): void
    {
        $this->publishes([
            __DIR__.'/../config/permixion.php' => config_path('permixion.php'),
        ], 'permixion-config');
    }

    protected function registerCommands(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateRole::class,
                CreatePermission::class,
                ShowPermissions::class,
            ]);
        }
    }

    protected function registerMiddleware(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');

        $router->aliasMiddleware('role', Middleware\RoleMiddleware::class);
        $router->aliasMiddleware('permission', Middleware\PermissionMiddleware::class);
        $router->aliasMiddleware('role_or_permission', Middleware\RoleOrPermissionMiddleware::class);
    }

    protected function registerBladeDirectives(): void
    {
        $this->callAfterResolving('blade.compiler', function (BladeCompiler $blade): void {
            // @role('admin') / @role('admin', $team)
            $blade->if('role', fn (string $role, $scope = null): bool => ($user = $this->permissibleUser()) instanceof HasPermissions && $user->hasRole($role, $scope));

            // @hasrole('admin') - alias
            $blade->if('hasrole', fn (string $role, $scope = null): bool => ($user = $this->permissibleUser()) instanceof HasPermissions && $user->hasRole($role, $scope));

            // @hasanyrole(['admin', 'manager'])
            $blade->if('hasanyrole', fn (array $roles, $scope = null): bool => ($user = $this->permissibleUser()) instanceof HasPermissions && $user->hasAnyRole($roles, $scope));

            // @hasallroles(['admin', 'manager'])
            $blade->if('hasallroles', fn (array $roles, $scope = null): bool => ($user = $this->permissibleUser()) instanceof HasPermissions && $user->hasAllRoles($roles, $scope));

            // @unlessrole('admin')
            $blade->if('unlessrole', fn (string $role, ?Scope $scope = null): bool => ! ($user = $this->permissibleUser()) instanceof HasPermissions || ! $user->hasRole($role, $scope));

            // @permission('posts.create')
            $blade->if('permission', fn (string $permission, $scope = null): bool => ($user = $this->permissibleUser()) instanceof HasPermissions && $user->hasPermissionTo($permission, $scope));

            // @haspermission - alias
            $blade->if('haspermission', fn (string $permission, $scope = null): bool => ($user = $this->permissibleUser()) instanceof HasPermissions && $user->hasPermissionTo($permission, $scope));
        });
    }

    protected function permissibleUser(): ?HasPermissions
    {
        $user = auth()->user();

        return $user instanceof HasPermissions ? $user : null;
    }

    protected function registerGatePermissions(): void
    {
        if (! config('permixion.register_gate', true)) {
            return;
        }

        Gate::before(function ($user, string $ability): ?true {
            if (! $user instanceof HasPermissions) {
                return null;
            }

            // Check super admin
            if (config('permixion.super_admin.enabled')) {
                $superAdminRole = config('permixion.super_admin.role');
                if ($user->hasRole($superAdminRole)) {
                    return true;
                }
            }

            // Resolve current scope
            $scope = app(Permixion::class)->resolveCurrentScope();

            // Check permission
            if ($user->hasPermissionTo($ability, $scope)) {
                return true;
            }

            return null; // Let other gates handle it
        });
    }
}
