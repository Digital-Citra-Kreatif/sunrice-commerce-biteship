<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Sunrice\Admin\Panel;
use Sunrice\Admin\PanelProvider;
use Sunrice\Cms\CmsPlugin;
use Sunrice\Commerce\CommercePlugin;
use Sunrice\CommerceBiteship\BiteshipPlugin;

/** The tests' panel: the CMS, the store and Biteship, in that order. */
final class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->path('admin')->plugin(CmsPlugin::make())->plugin(CommercePlugin::make())->plugin(BiteshipPlugin::make());
    }
}
