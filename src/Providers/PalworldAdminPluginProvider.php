<?php

namespace Soul\PalworldAdmin\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Soul\PalworldAdmin\Http\Controllers\PalExportController;

class PalworldAdminPluginProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Route::prefix('palworld-admin')->middleware('web')->group(function (): void {
            // versioned via ?v= query param for cache busting - content is immutable per version
            Route::get('/assets/palexport.js', [PalExportController::class, 'asset'])
                ->name('palworld-admin.assets.palexport');

            Route::middleware('auth')->group(function (): void {
                Route::get('/{server:uuid_short}/level-sav', [PalExportController::class, 'levelSav'])
                    ->name('palworld-admin.level-sav');
                Route::get('/{server:uuid_short}/level-sav/stat', [PalExportController::class, 'levelSavStat'])
                    ->name('palworld-admin.level-sav.stat');
            });
        });
    }
}
