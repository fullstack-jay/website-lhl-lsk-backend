<?php

namespace App\Providers;

use App\Repositories\PendaftaranRepository;
use App\Services\PendaftaranService;
use Illuminate\Support\ServiceProvider;

class PendaftaranServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register Repository
        $this->app->bind(PendaftaranRepository::class, function ($app) {
            return new PendaftaranRepository($app);
        });

        // Register Service
        $this->app->bind(PendaftaranService::class, function ($app) {
            return new PendaftaranService($app->make(PendaftaranRepository::class));
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
