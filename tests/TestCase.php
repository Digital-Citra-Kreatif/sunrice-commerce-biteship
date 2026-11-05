<?php

declare(strict_types=1);

namespace Sunrice\CommerceBiteship\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\SocialiteServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Honeypot\HoneypotServiceProvider;
use Spatie\OneTimePasswords\OneTimePasswordsServiceProvider;
use Sunrice\Admin\AdminServiceProvider;
use Sunrice\Cms\CmsServiceProvider;
use Sunrice\Commerce\CommerceServiceProvider;
use Sunrice\CommerceBiteship\BiteshipServiceProvider;
use Workbench\App\Models\User;
use Workbench\App\Providers\AdminPanelProvider;

/** A store with the panel, the CMS, Commerce and this package, as commerce's own tests set it up. */
class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            AdminServiceProvider::class,
            CmsServiceProvider::class,
            HoneypotServiceProvider::class,
            OneTimePasswordsServiceProvider::class,
            SocialiteServiceProvider::class,
            CommerceServiceProvider::class,
            BiteshipServiceProvider::class,
            AdminPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('sunrice-cms.locales', ['id', 'en']);
        $app['config']->set('sunrice-admin.auth.password_uncompromised', false);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('app.key', 'base64:2fl+Ktvkfl+Fve4Qp/sF3rJf2fDR2lHo4SY9mZhJb+s=');
        $app['config']->set('inertia.testing.ensure_pages_exist', false);
        $app['config']->set('commerce-biteship.api_key', 'biteship_test.key');
    }

    /**
     * The workbench's migrations run once, with RefreshDatabase. Not
     * loadMigrationsFrom(): it rolls them back after every test, and on
     * MySQL that DDL commits the test's transaction.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->app->make('migrator')->path(__DIR__.'/../workbench/database/migrations');
    }

    /** Each request starts with fresh per-request services, as it would in a real request. */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app->forgetScopedInstances();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
