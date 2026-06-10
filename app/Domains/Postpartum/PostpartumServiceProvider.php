<?php

namespace App\Domains\Postpartum;

use App\Domains\Postpartum\Models\Newborn;
use App\Domains\Postpartum\Models\PostpartumClient;
use App\Domains\Postpartum\Policies\PostpartumClientPolicy;
use App\Domains\Postpartum\Services\EpdsCalculatorService;
use App\Domains\Postpartum\Services\NewbornAnomalyDetectorService;
use App\Domains\Postpartum\Services\SbaService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Postpartum Domain Service Provider
 *
 * AppServiceProvider 또는 config/app.php 의 providers 배열에 등록:
 *  App\Domains\Postpartum\PostpartumServiceProvider::class
 */
class PostpartumServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton 등록 (도메인 서비스)
        $this->app->singleton(SbaService::class);
        $this->app->singleton(EpdsCalculatorService::class);
        $this->app->singleton(NewbornAnomalyDetectorService::class);
    }

    public function boot(): void
    {
        Gate::policy(PostpartumClient::class, PostpartumClientPolicy::class);
        Gate::policy(Newborn::class, \App\Domains\Postpartum\Policies\NewbornPolicy::class);
    }
}
