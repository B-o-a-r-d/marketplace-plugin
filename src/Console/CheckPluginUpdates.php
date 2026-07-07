<?php

namespace Board\Marketplace\Console;

use Board\Marketplace\PluginInstaller;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('plugins:check-updates')]
#[Description('Refresh the latest available release (and breaking flag) for each installed plugin package.')]
class CheckPluginUpdates extends Command
{
    public function handle(PluginInstaller $installer): int
    {
        $installer->checkUpdates();

        $this->info('Plugin update check complete.');

        return self::SUCCESS;
    }
}
