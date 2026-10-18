<?php

declare(strict_types=1);

namespace Sunrice\CommerceBiteship\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\ServiceProvider as InertiaServiceProvider;
use Laravel\Mcp\Server\McpServiceProvider;
use Laravel\Socialite\SocialiteServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Honeypot\HoneypotServiceProvider;
use Spatie\OneTimePasswords\OneTimePasswordsServiceProvider;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Sitemap\SitemapServiceProvider;
use Sunrice\Commerce\CommerceServiceProvider;
use Sunrice\CommerceBiteship\BiteshipServiceProvider;
use Sunrice\Frontend\RouteMatcher;
use Sunrice\SunriceServiceProvider;
use Workbench\App\Models\User;

/** A store with Sunrice, commerce and this package, as commerce's own tests set it up. */
class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        RouteMatcher::flush(keepCache: true);
        parent::setUp();
    }

    protected function getPackageProviders($app): array
    {
        return [
            SunriceServiceProvider::class,
            InertiaServiceProvider::class,
            PermissionServiceProvider::class,
            HoneypotServiceProvider::class,
            SitemapServiceProvider::class,
            McpServiceProvider::class,
            OneTimePasswordsServiceProvider::class,
            SocialiteServiceProvider::class,
            CommerceServiceProvider::class,
            BiteshipServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('sunrice.locales', [
            'main' => 'id',
            'available' => ['id', 'en'],
            'names' => ['id' => 'Bahasa Indonesia', 'en' => 'English'],
        ]);
        $app['config']->set('sunrice.auth.user_model', User::class);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('app.key', 'base64:2fl+Ktvkfl+Fve4Qp/sF3rJf2fDR2lHo4SY9mZhJb+s=');
        $app['config']->set('inertia.testing.ensure_pages_exist', false);
        $app['config']->set('commerce-biteship.api_key', 'biteship_test.key');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');
    }

    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app->forgetScopedInstances();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
