<?php

namespace App\Providers;

use App\Services\DiscordAlertService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DiscordAlertService::class, fn () => new DiscordAlertService);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());
        BinaryFileResponse::trustXSendfileTypeHeader();
    }
}
