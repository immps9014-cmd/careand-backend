<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     */
    protected function redirectTo(Request $request): ?string
    {
        // API 전용 백엔드 — 웹 로그인 라우트가 없으므로 리다이렉트하지 않는다.
        // (route('login') 평가 시 RouteNotFoundException 500이 나던 문제)
        return null;
    }
}
