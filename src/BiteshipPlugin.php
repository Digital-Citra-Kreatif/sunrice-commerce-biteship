<?php

declare(strict_types=1);

namespace Sunrice\CommerceBiteship;

use Sunrice\Admin\Assets\PluginBundle;
use Sunrice\Admin\BasePlugin;
use Sunrice\Admin\Panel;
use Sunrice\Commerce\CommercePlugin;

/**
 * Biteship in the admin: the order page's booking routes and its panel's
 * script, loaded after commerce's on commerce's pages. Add it after
 * commerce in the panel provider:
 *
 *     $panel->plugin(CmsPlugin::make())->plugin(CommercePlugin::make())->plugin(BiteshipPlugin::make());
 */
final class BiteshipPlugin extends BasePlugin
{
    public const ID = 'commerce-biteship';

    public function id(): string
    {
        return self::ID;
    }

    /** @return list<string> */
    public function requires(): array
    {
        return [CommercePlugin::ID];
    }

    public function register(Panel $panel): void
    {
        // Names sunrice.admin.commerce-biteship.*, guarded in AdminController (commerce.shipments.manage).
        $panel->routes(function (): void {
            require __DIR__.'/../routes/admin.php';
        });
        $panel->bundle(PluginBundle::make(self::ID)->named('Biteship')
            ->publishedFrom(dirname(__DIR__).'/resources/dist', public_path('vendor/commerce-biteship'))
            ->module('admin.js')->extends('Commerce/'));
    }
}
