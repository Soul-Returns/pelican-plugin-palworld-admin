<?php

namespace Soul\PalworldAdmin;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Soul\PalworldAdmin\Filament\Pages\PalworldPlayers;
use Soul\PalworldAdmin\Filament\Pages\PalworldWorldSettings;

class PalworldAdminPlugin implements Plugin
{
    public function getId(): string
    {
        return 'palworld-admin';
    }

    public function register(Panel $panel): void
    {
        $panel->pages([
            PalworldPlayers::class,
            PalworldWorldSettings::class,
        ]);
    }

    public function boot(Panel $panel): void
    {
        // Is run only when the panel that the plugin is being registered to is actually in-use. It is executed by a middleware class.
    }
}
