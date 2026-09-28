<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use RuntimeException;

trait CreatesApplication
{
    /**
     * Creates the application.
     *
     * 안전장치(2026-09-29, S6): 시험은 RefreshDatabase 로 DB 를 통째로 지운다. 설정 캐시(bootstrap/cache/config.php)가 있으면
     * phpunit.xml 의 DB_DATABASE 가 무시돼 **운영 DB 가 지워진다**. DB 이름이 _test 로 끝나지 않으면 아무것도 하기 전에 멈춘다.
     * 시험은 scripts/run-tests.sh 로 복사본에서 돌린다(운영 디렉터리는 설정이 캐시돼 있음).
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        $db = (string) $app['config']->get('database.connections.' . $app['config']->get('database.default') . '.database');
        if (!str_ends_with($db, '_test')) {
            throw new RuntimeException("시험 중단: 대상 DB '{$db}' 가 시험용(_test)이 아닙니다. scripts/run-tests.sh 로 실행하세요.");
        }

        return $app;
    }
}
