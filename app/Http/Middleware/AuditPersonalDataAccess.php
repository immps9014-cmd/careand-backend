<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 개인정보 접근 감사로그 — 사업계획서 3.3 「모든 개인정보 접근 행위 감사로그 기록」(2026-09-28, 구현계획 S2).
 * 기록 대상: 관리자 API 전체 + 개인정보 자원(대상자·환자·산모·아동·케어 세션·보호자·인력 상세·기관 소속 인력).
 * 대시보드 집계 조회처럼 개인을 특정하지 않는 반복 호출은 제외한다.
 * 응답이 나간 뒤(terminate) 기록해 요청 지연을 만들지 않고, 기록 실패는 요청 결과를 바꾸지 않는다.
 * 사유(WHY)는 요청 헤더 X-Access-Reason (URL 인코딩 허용).
 */
class AuditPersonalDataAccess
{
    private const PERSONAL = '#^api/v1/(seniors|nursing|postpartum|children|mental-care|care-sessions|guardians|caregivers/\d+|organizations/me/caregivers|housekeeping)#';
    private const SKIP = '#^api/v1/admin/(dashboard|ontology)(/|$)#';

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $path = $request->path();
            $isAdmin = str_starts_with($path, 'api/v1/admin/');
            if (!$isAdmin && !preg_match(self::PERSONAL, $path)) {
                return;
            }
            if ($isAdmin && $request->isMethod('GET') && preg_match(self::SKIP, $path)) {
                return;
            }
            $user = auth('api')->user();
            if (!$user) {
                return;
            }
            $route = $request->route();
            $params = $route ? $route->parameters() : [];
            $uri = $route ? $route->uri() : $path;
            $entityId = null;
            foreach ($params as $v) {
                if (is_numeric($v)) { $entityId = (int) $v; break; }
            }
            $seg = explode('/', preg_replace('#^api/v1/(admin/)?#', '', $uri));
            $reason = $request->header('X-Access-Reason');

            AuditLog::create([
                'actor_id' => $user->id,
                'action' => $request->method() . ' ' . $uri,
                'entity_type' => $seg[0] ?? null,
                'entity_id' => $entityId,
                'details' => [
                    'status' => $response->getStatusCode(),
                    'role' => $user->role,
                    'params' => $params,
                    'query_keys' => array_keys($request->query()),
                ],
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'reason' => $reason !== null ? mb_substr(rawurldecode($reason), 0, 300) : null,
            ]);
        } catch (\Throwable $e) {
            Log::warning('감사로그 기록 실패: ' . $e->getMessage());
        }
    }
}
