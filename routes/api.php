<?php

use App\Http\Controllers\CommanderController;
use App\Http\Controllers\DataDownloadController;
use App\Http\Controllers\FrontierAuthController;
use App\Http\Controllers\FrontierCApiController;
use App\Http\Controllers\GalaxyController;
use App\Http\Controllers\GalnetNewsController;
use App\Http\Controllers\MarketController;
use App\Http\Controllers\SanctumAuthController;
use App\Http\Controllers\Search\MarketCommodityController;
use App\Http\Controllers\Search\MarketTradeRouteController;
use App\Http\Controllers\Search\SystemDistanceController;
use App\Http\Controllers\Search\SystemInformationController;
use App\Http\Controllers\Search\SystemNavRouteController;
use App\Http\Controllers\StationController;
use App\Http\Controllers\StatisticsController;
use App\Http\Controllers\SystemBodyController;
use App\Http\Controllers\SystemController;
use App\Http\Controllers\SystemLastUpdatedController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

/*
|--------------------------------------------------------------------------
| /auth routes
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::get('login', fn () => redirect('https://edcs.app'))->name('login');

    // Sanctum Auth
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [SanctumAuthController::class, 'me']);
        Route::post('logout', [SanctumAuthController::class, 'logout']);
    });

    // Frontier Auth
    Route::prefix('frontier')->group(function () {
        Route::get('login', [FrontierAuthController::class, 'login'])->name('frontier.auth.login');
        Route::get('callback', [FrontierAuthController::class, 'callback'])->name('frontier.auth.callback');
        Route::post('me', [FrontierAuthController::class, 'me'])->name('frontier.auth.me');
    });
});

Route::middleware(['auth:sanctum', 'has.cmdr'])->group(function () {
    Route::put('commander', [CommanderController::class, 'update']);
});

Route::middleware('auth:sanctum')->prefix('frontier')->group(function () {
    Route::prefix('capi')->group(function () {
        Route::get('profile', [FrontierCApiController::class, 'profile']);
        Route::get('journal', [FrontierCApiController::class, 'journal']);
        Route::get('communitygoals', [FrontierCApiController::class, 'communityGoals']);
    });
});

/*
|--------------------------------------------------------------------------
| /systems routes
|
| Note: Order is important! Laravel matches routes top-down, defining the custom
| routes before the resource route prevents e.g. "GET /systems/last-updated"
| from being incorrectly captured by "GET /systems/{system}"
|--------------------------------------------------------------------------
*/
Route::prefix('systems')->name('systems.')->group(function () {

    Route::get('last-updated', SystemLastUpdatedController::class);

    Route::prefix('search')->group(function () {
        Route::get('information', SystemInformationController::class);
        Route::get('distance', SystemDistanceController::class);
        Route::get('route', SystemNavRouteController::class);
    });
});

Route::resource('systems', SystemController::class);

/*
|--------------------------------------------------------------------------
| /galaxy routes
|--------------------------------------------------------------------------
*/
Route::get('galaxy/manifest', [GalaxyController::class, 'manifest']);
Route::get('galaxy/tiles/{path}', [GalaxyController::class, 'tile'])->where('path', '.*');

/*
|--------------------------------------------------------------------------
| /bodies routes
|--------------------------------------------------------------------------
*/
Route::resource('bodies', SystemBodyController::class);

/*
|--------------------------------------------------------------------------
| /stations routes
|--------------------------------------------------------------------------
*/
Route::prefix('stations')->group(function () {
    Route::get('{slug}/market', [MarketController::class, 'getMarketDataForStation']);

    Route::prefix('search')->group(function () {
        Route::get('commodity', MarketCommodityController::class);
        Route::get('trade-route', MarketTradeRouteController::class);
    });
});

Route::resource('stations', StationController::class);

/*
|--------------------------------------------------------------------------
| /statistics routes
|--------------------------------------------------------------------------
*/
Route::get('statistics', StatisticsController::class);

/*
|--------------------------------------------------------------------------
| /galnet routes
|--------------------------------------------------------------------------
*/
Route::prefix('galnet')->group(function () {
    Route::resource('news', GalnetNewsController::class);
});

/*
|--------------------------------------------------------------------------
| /downloads routes
|--------------------------------------------------------------------------
*/
Route::prefix('downloads')->name('downloads.')->group(function () {
    Route::get('manifest', [DataDownloadController::class, 'manifest'])->name('manifest');
    Route::get('{type}', [DataDownloadController::class, 'download'])->name('download');
});
