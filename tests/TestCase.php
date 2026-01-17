<?php

namespace RobinsonRyan\Permixion\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use RobinsonRyan\Taxon\TaxonServiceProvider;
use RobinsonRyan\Permixion\PermixionServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabase();
    }

    protected function getPackageProviders($app): array
    {
        return [
            TaxonServiceProvider::class,
            PermixionServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('taxon.id_type', 'incrementing');
        $app['config']->set('permixion.strict', true);
    }

    protected function setUpDatabase(): void
    {
        // Load Taxon migrations - they should be in vendor after composer install
        $taxonMigrations = __DIR__.'/../vendor/robinsonryan/taxon/database/migrations';
        if (is_dir($taxonMigrations)) {
            $this->loadMigrationsFrom($taxonMigrations);
        }

        // Create test tables
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamps();
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }
}
