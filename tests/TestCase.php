<?php

namespace RobinsonRyan\Permixion\Tests;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use PDO;
use PDOException;
use RobinsonRyan\Permixion\PermixionServiceProvider;
use RobinsonRyan\Taxon\TaxonServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    private static bool $databaseEnsured = false;

    protected function getPackageProviders($app): array
    {
        return [
            TaxonServiceProvider::class,
            PermixionServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // The suite runs on PostgreSQL, the engine consumers run, never SQLite:
        // SQLite collapses uuid, bigint and varchar into one loose affinity, so a
        // column-type defect is unfalsifiable there. A checkout nested at
        // .claude/worktrees/<slug>/ gets its own testing_wt_<slug> database
        // (created on first run), so parallel worktrees never share a schema.
        // PERMIXION_TEST_DB_* override everything.
        $connection = self::connectionConfig(self::databaseName());

        $this->ensureDatabaseExists($connection);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $connection);

        $app['config']->set('taxon.id_type', 'incrementing');
        $app['config']->set('permixion.strict', true);

        // Taxon publishes its migrations rather than loading them, and the fixture
        // tables stand in for a consumer's. Both are registered as migrator paths,
        // not loadMigrationsFrom(), so the schema is built once per process and
        // each test runs in a transaction.
        $app->afterResolving('migrator', function (Migrator $migrator): void {
            $migrator->path(__DIR__.'/../vendor/robinsonryan/taxon/database/migrations');
            $migrator->path(__DIR__.'/Fixtures/database/migrations');
        });
    }

    /**
     * @return array<string, mixed>
     */
    public static function connectionConfig(string $database): array
    {
        return [
            'driver' => 'pgsql',
            'host' => env('PERMIXION_TEST_DB_HOST', 'db'),
            'port' => (int) env('PERMIXION_TEST_DB_PORT', 5432),
            'database' => $database,
            'username' => env('PERMIXION_TEST_DB_USERNAME', 'db'),
            'password' => env('PERMIXION_TEST_DB_PASSWORD', 'db'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];
    }

    public static function databaseName(): string
    {
        $override = env('PERMIXION_TEST_DB_DATABASE');

        if (is_string($override) && $override !== '') {
            return $override;
        }

        if (preg_match('#/\.claude/worktrees/([^/]+)/#', __DIR__, $matches) === 1) {
            return 'testing_wt_'.preg_replace('/[^a-z0-9_]+/', '_', strtolower($matches[1]));
        }

        return 'testing';
    }

    /**
     * @param  array<string, mixed>  $connection
     */
    private function ensureDatabaseExists(array $connection): void
    {
        if (self::$databaseEnsured) {
            return;
        }

        self::$databaseEnsured = true;

        $name = (string) $connection['database'];
        $pdo = new PDO(
            sprintf('pgsql:host=%s;port=%d;dbname=postgres', $connection['host'], $connection['port']),
            (string) $connection['username'],
            (string) $connection['password'],
        );

        $statement = $pdo->prepare('select 1 from pg_database where datname = ?');
        $statement->execute([$name]);

        if ($statement->fetchColumn() !== false) {
            return;
        }

        try {
            $pdo->exec(sprintf('CREATE DATABASE "%s"', $name));
        } catch (PDOException) {
            // Another process created it between the check and the create.
        }
    }
}
