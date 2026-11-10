<?php

namespace RobinsonRyan\Permixion;

use ArrayObject;
use Illuminate\Database\Eloquent\Model;
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
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/permixion.php',
            'permixion'
        );

        $this->app->singleton(Permixion::class);
        $this->app->alias(Permixion::class, 'permixion');

        // Effective permissions are memoised per request, never across: `scoped`
        // gives Octane and queue workers a fresh memo for every request and job.
        $this->app->scoped('permixion.effective-memo', fn (): ArrayObject => new ArrayObject);
    }

    public function boot(): void
    {
        $this->publishConfig();
        $this->loadMigrations();
        $this->publishMigrations();
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

    /**
     * Load the package's migration unless the consumer has published it —
     * publishing means they took ownership, and loading it too would run it twice.
     */
    protected function loadMigrations(): void
    {
        if (! $this->migrationsPublished()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }
    }

    protected function publishMigrations(): void
    {
        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'permixion-migrations');
    }

    private function migrationsPublished(): bool
    {
        return count(glob(database_path('migrations/*_create_permixion_assignment_throughs_table.php')) ?: []) > 0;
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

        // The ?true return type is load-bearing: it makes a hard deny structurally
        // impossible. A Gate::before returning false would refuse every unrelated
        // gate in the consuming app, so the signature forecloses that outright.
        //
        // $arguments arrives as a single array — Laravel does not spread it.
        Gate::before(function ($user, string $ability, array $arguments = []): ?true {
            // Using the HasRoles trait is deliberately NOT enough. Declaring the
            // contract is how a model consents to Permixion outranking its policies.
            if (! $user instanceof HasPermissions) {
                return null;
            }

            $scope = app(Permixion::class)->resolveCurrentScope();

            // Super admin is deliberate god-mode and stays unconditional — it is
            // not narrowed by defer_to_policies. "Super admin" means an UNCAPPED
            // one: a super-admin role held through a capped holder, under a
            // withCeiling(), or by an inactive holder is not god-mode.
            if ($user instanceof Model
                && app(Permixion::class)->effectivePermissionsFor($user, $scope)->isUnlimited()) {
                return true;
            }

            // A permission name cannot answer a per-instance question. When the
            // caller supplied a subject and the app has opted in, decline so the
            // policy — the only thing that can see the instance — decides.
            if (config('permixion.gate.defer_to_policies', false)
                && app(Permixion::class)->argumentsCarryPolicySubject($arguments)) {
                return null;
            }

            if ($user->hasPermissionTo($ability, $scope)) {
                return true;
            }

            return null; // Let other gates handle it
        });
    }
}
