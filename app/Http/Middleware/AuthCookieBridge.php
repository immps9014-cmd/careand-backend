<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * 회원 웹 로그인 토큰을 httpOnly 쿠키로(2026-10-05, CAREN-TODO-01 「로그인 토큰 보관」).
 * 회원 웹만 `X-Auth-Mode: cookie` 를 보낸다 — 모바일 앱·공개 웹·관리자 웹은 기존 Bearer 방식 그대로.
 *
 * 응답: 쿠키 모드 요청의 JSON 에 access_token / refresh_token 이 있으면 쿠키로 옮기고 본문에서 지운다
 *       (로그인·가입·소셜 로그인·갱신 어느 경로든 같은 처리). 로그아웃이면 쿠키를 지운다.
 * 요청: Authorization 헤더가 없고 쿠키가 있으면 헤더로 바꿔 넣는다(갱신 경로는 리프레시 쿠키).
 *       쿠키로 인증하는 쓰기 요청은 X-Requested-With: XMLHttpRequest 가 있어야 한다 — 다른 사이트의 폼 전송(CSRF) 차단.
 *       (CORS 는 * + credentials 불가라 다른 출처 JS 는 쿠키를 실어 보낼 수 없다)
 */
class AuthCookieBridge
{
    public const ACCESS = 'caren_at';
    public const REFRESH = 'caren_rt';

    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->is('api/*')) {
            return $next($request);
        }
        $isRefresh = $request->is('api/v1/auth/refresh');
        // 예전 회원 웹(localStorage 토큰)에서 옮겨 오는 첫 갱신 — 헤더로 온 리프레시 토큰을 쿠키로 심어 준다
        $headerRefresh = $isRefresh ? $request->bearerToken() : null;

        if (!$request->headers->has('Authorization')) {
            $token = $isRefresh ? $request->cookies->get(self::REFRESH) : $request->cookies->get(self::ACCESS);
            if (is_string($token) && $token !== '') {
                if (!in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && $request->header('X-Requested-With') !== 'XMLHttpRequest') {
                    return response()->json(['success' => false, 'error_code' => 'CSRF_HEADER_REQUIRED', 'message' => '잘못된 요청이에요. 화면을 새로 고쳐 주세요.'], 419);
                }
                $request->headers->set('Authorization', 'Bearer ' . $token);
            }
        }

        $response = $next($request);

        if ($request->header('X-Auth-Mode') !== 'cookie') {
            return $response;
        }
        if ($request->is('api/v1/auth/logout')) {
            return $this->forget($response);
        }
        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            $data = $response->getData(true);
            if (!is_array($data) || (!isset($data['access_token']) && !isset($data['refresh_token']))) {
                return $response;
            }
            if (!empty($data['access_token'])) {
                $response->headers->setCookie($this->cookie(self::ACCESS, $data['access_token'], (int) config('jwt.refresh_ttl'), '/api'));
            }
            if (!empty($data['refresh_token'])) {
                $response->headers->setCookie($this->cookie(self::REFRESH, $data['refresh_token'], (int) config('jwt.refresh_ttl'), '/api/v1/auth'));
            }
            if ($headerRefresh && empty($data['refresh_token'])) {
                $response->headers->setCookie($this->cookie(self::REFRESH, $headerRefresh, (int) config('jwt.refresh_ttl'), '/api/v1/auth'));
            }
            unset($data['access_token'], $data['refresh_token']);
            $data['token_storage'] = 'cookie';
            $response->setData($data);
        } elseif ($isRefresh && $response->getStatusCode() === 401) {
            return $this->forget($response);   // 갱신 실패 = 세션 끝
        }

        return $response;
    }

    /** 액세스 쿠키 수명은 리프레시와 같게 — 만료 판정은 JWT exp 가 하고, 쿠키가 먼저 사라져 갱신 기회를 잃지 않게 한다 */
    private function cookie(string $name, string $value, int $minutes, string $path): Cookie
    {
        return Cookie::create($name, $value, now()->addMinutes($minutes), $path, null, true, true, false, Cookie::SAMESITE_LAX);
    }

    private function forget(Response $response): Response
    {
        $response->headers->setCookie(Cookie::create(self::ACCESS, '', 1, '/api', null, true, true, false, Cookie::SAMESITE_LAX));
        $response->headers->setCookie(Cookie::create(self::REFRESH, '', 1, '/api/v1/auth', null, true, true, false, Cookie::SAMESITE_LAX));

        return $response;
    }
}
