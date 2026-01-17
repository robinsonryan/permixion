<?php

namespace RobinsonRyan\Permixion;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use RobinsonRyan\Permixion\Commands\CreatePermission;
use RobinsonRyan\Permixion\Commands\CreateRole;
use RobinsonRyan\Permixion\Commands\ShowPermissions;

class PermixionServiceProvider extends ServiceProvider
{
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
        $router = $this->app['router'];

        $router->aliasMiddleware('role', Middleware\RoleMiddleware::class);
        $router->aliasMiddleware('permission', Middleware\PermissionMiddleware::class);
        $router->aliasMiddleware('role_or_permission', Middleware\RoleOrPermissionMiddleware::class);
    }

    protected function registerBladeDirectives(): void
    {
        $this->callAfterResolving('blade.compiler', function (BladeCompiler $blade) {
            // @role('admin') / @role('admin', $team)
            $blade->if('role', function (string $role, $scope = null) {
                return auth()->check() && auth()->user()->hasRole($role, $scope);
            });

            // @hasrole('admin') - alias
            $blade->if('hasrole', function (string $role, $scope = null) {
                return auth()->check() && auth()->user()->hasRole($role, $scope);
            });

            // @hasanyrole(['admin', 'manager'])
            $blade->if('hasanyrole', function (array $roles, $scope = null) {
                return auth()->check() && auth()->user()->hasAnyRole($roles, $scope);
            });

            // @hasallroles(['admin', 'manager'])
            $blade->if('hasallroles', function (array $roles, $scope = null) {
                return auth()->check() && auth()->user()->hasAllRoles($roles, $scope);
            });

            // @unlessrole('admin')
            $blade->if('unlessrole', function (string $role, $scope = null) {
                return ! auth()->check() || ! auth()->user()->hasRole($role, $scope);
            });

            // @permission('posts.create')
            $blade->if('permission', function (string $permission, $scope = null) {
                return auth()->check() && auth()->user()->hasPermissionTo($permission, $scope);
            });

            // @haspermission - alias
            $blade->if('haspermission', function (string $permission, $scope = null) {
                return auth()->check() && auth()->user()->hasPermissionTo($permission, $scope);
            });
        });
    }

    protected function registerGatePermissions(): void
    {
        if (! config('permixion.register_gate', true)) {
            return;
        }

        Gate::before(function ($user, string $ability) {
            if (! method_exists($user, 'hasPermissionTo')) {
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
