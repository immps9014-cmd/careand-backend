<?php

namespace App\Providers;

use App\Services\External\AiService;
use App\Services\External\CredentialVerifier;
use App\Services\External\FcmService;
use App\Services\External\HometaxService;
use App\Services\External\KuksiwonService;
use App\Services\External\MohwService;
use App\Services\External\NhisService;
use App\Services\External\PgService;
use App\Services\External\PrivateQualService;
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

        // 한국보건의료인국가시험원(국시원) — 간호사 면허
        $this->app->singleton(KuksiwonService::class, function ($app) {
            return new KuksiwonService(
                baseUrl: config('services.kuksiwon.url'),
                apiKey: config('services.kuksiwon.api_key', ''),
            );
        });

        // 민간자격정보서비스(직능원) — 산후관리사·간병사 등
        $this->app->singleton(PrivateQualService::class, function ($app) {
            return new PrivateQualService(
                baseUrl: config('services.pqi.url'),
                apiKey: config('services.pqi.api_key', ''),
            );
        });

        // 자격 진위조회 디스패처 (자격종류 → 기관 라우팅)
        $this->app->singleton(CredentialVerifier::class, function ($app) {
            return new CredentialVerifier(
                $app->make(MohwService::class),
                $app->make(KuksiwonService::class),
                $app->make(PrivateQualService::class),
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
