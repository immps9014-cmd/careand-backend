<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 개인정보 다운로드 — 사업계획서 3.3 「개인정보 다운로드는 슈퍼관리자만, 사유 입력·감사로그 필수」·
 * 기능 19 「CSV 다운로드(개인정보 마스킹)」 (2026-09-28, 구현계획 S2-5).
 * 권한: config/admin_rbac.php 'exports' 영역(슈퍼관리자만). 사유: 요청 헤더 X-Access-Reason(5자 이상, 없으면 거부).
 * 이름·이메일·휴대폰은 마스킹하고, 주소 등 상세 개인정보는 넣지 않는다.
 */
class ExportController extends Controller
{
    /** GET /v1/admin/exports/members?role=guardian|caregiver|organization */
    public function members(Request $request)
    {
        $reason = trim(rawurldecode((string) $request->header('X-Access-Reason', '')));
        if (mb_strlen($reason) < 5) {
            return response()->json([
                'success' => false,
                'error_code' => 'REASON_REQUIRED',
                'message' => '개인정보 다운로드 사유를 5자 이상 입력해야 합니다.',
            ], 422);
        }
        $role = $request->query('role');

        $rows = DB::table('users')
            ->leftJoin('caregivers as cg', function ($j) {
                $j->on('cg.user_id', '=', 'users.id')->whereNull('cg.deleted_at');
            })
            ->leftJoin('guardians as g', 'g.user_id', '=', 'users.id')
            ->whereNull('users.deleted_at')
            ->whereIn('users.role', ['guardian', 'caregiver', 'organization'])
            ->when(in_array($role, ['guardian', 'caregiver', 'organization'], true), fn ($q) => $q->where('users.role', $role))
            ->orderBy('users.id')
            ->get(['users.id', 'users.role', 'users.name', 'users.email', 'users.phone', 'users.status', 'users.created_at',
                'cg.status as caregiver_status', 'cg.service_domains', 'g.intent']);

        AuditLog::create([
            'actor_id' => $request->user()->id,
            'action' => 'export.members.csv',
            'entity_type' => 'exports',
            'details' => ['role' => $role ?: 'all', 'rows' => $rows->count(), 'masked' => true],
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'reason' => mb_substr($reason, 0, 300),
        ]);

        $roleLabel = ['guardian' => '보호자', 'caregiver' => '돌봄전문가', 'organization' => '기관'];
        $file = 'careand_members_' . now()->format('Ymd_His') . '.csv';

        return new StreamedResponse(function () use ($rows, $roleLabel, $reason, $request) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // 엑셀 한글 깨짐 방지(UTF-8 BOM)
            fputcsv($out, ['# 개인정보 마스킹 파일 — 다운로드: ' . $request->user()->email . ' · ' . now()->format('Y-m-d H:i') . ' · 사유: ' . $reason]);
            fputcsv($out, ['회원ID', '구분', '이름', '이메일', '휴대폰', '상태', '가입일', '인력 검증상태', '서비스 도메인·요청 성격']);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r->id,
                    $roleLabel[$r->role] ?? $r->role,
                    self::maskName($r->name),
                    self::maskEmail($r->email),
                    self::maskPhone($r->phone),
                    $r->status,
                    substr((string) $r->created_at, 0, 10),
                    $r->caregiver_status ?? '',
                    $r->role === 'caregiver' ? ($r->service_domains ?? '') : ($r->intent ?? ''),
                ]);
            }
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $file . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** 홍길동 → 홍○○, 남궁민수 → 남○○○ */
    public static function maskName(?string $v): string
    {
        $v = (string) $v;
        $len = mb_strlen($v);
        return $len <= 1 ? $v : mb_substr($v, 0, 1) . str_repeat('○', $len - 1);
    }

    /** abcdef@x.com → ab****@x.com */
    public static function maskEmail(?string $v): string
    {
        $v = (string) $v;
        if (!str_contains($v, '@')) {
            return self::maskName($v);
        }
        [$id, $dom] = explode('@', $v, 2);
        return mb_substr($id, 0, 2) . str_repeat('*', max(mb_strlen($id) - 2, 2)) . '@' . $dom;
    }

    /** 01012345678 → 010-****-5678 */
    public static function maskPhone(?string $v): string
    {
        $d = preg_replace('/\D/', '', (string) $v);
        return strlen($d) >= 8 ? substr($d, 0, 3) . '-****-' . substr($d, -4) : ($d === '' ? '' : '****');
    }
}
