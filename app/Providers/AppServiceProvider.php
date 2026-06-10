<?php

namespace App\Providers;

use App\Services\External\AiService;
use App\Services\External\FcmService;
use App\Services\External\HometaxService;
use App\Services\External\MohwService;
use App\Services\External\NhisService;
use App\Services\External\PgService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // 보건복지부 자격관리시스템
        $this->app->singleton(MohwService::class, function ($app) {
            return new MohwService(
                baseUrl: config('services.mohw.url'),
                apiKey: config('services.mohw.api_key', ''),
            );
        });

        // 국민건강보험공단
        $this->app->singleton(NhisService::class, function ($app) {
            return new NhisService(
                baseUrl: config('services.nhis.url'),
                apiKey: config('services.nhis.api_key', ''),
            );
        });

        // PG사 (KG이니시스 / 토스)
        $this->app->singleton(PgService::class, function ($app) {
            return new PgService(
                provider: config('services.pg.provider'),
                mid: config('services.pg.mid', ''),
                apiKey: config('services.pg.api_key', ''),
                signKey: config('services.pg.sign_key'),
            );
        });

        // 국세청 홈택스
        $this->app->singleton(HometaxService::class, function ($app) {
            return new HometaxService(
                baseUrl: config('services.hometax.url'),
                bizNo: config('services.hometax.biz_no', ''),
                apiKey: config('services.hometax.api_key', ''),
            );
        });

        // AI 마이크로서비스
        $this->app->singleton(AiService::class, function ($app) {
            return new AiService(
                baseUrl: config('services.ai.url'),
                token: config('services.ai.token', ''),
                timeout: config('services.ai.timeout', 30),
            );
        });

        // FCM (Legacy HTTP API)
        $this->app->singleton(FcmService::class, function ($app) {
            return new FcmService(
                serverKey: config('services.fcm.server_key', ''),
            );
        });
        // NotificationService는 FcmService 의존을 자동 해소 (별도 binding 불필요)
    }

    public function boot(): void
    {
        //
    }
}
