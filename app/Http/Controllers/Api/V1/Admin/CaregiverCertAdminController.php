<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\CareandCertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * 케어앤에듀 인증 돌봄전문가 관리(2026-10-07) — RBAC 영역 caregivers(조회 운영 3등급, 부여·취소 슈퍼·지점장).
 * 목록은 ① 자격 보유(취소 포함) ② 기준 충족·미부여 ③ 기준 근접(활동 또는 후기가 기준의 절반 이상)으로 나눠 보여 준다.
 */
class CaregiverCertAdminController extends Controller
{
    public function __construct(private CareandCertService $svc)
    {
    }

    /** GET /v1/admin/caregivers/certifications */
    public function index(): JsonResponse
    {
        $criteria = $this->svc->criteria();
        $cgs = DB::table('caregivers as c')->join('users as u', 'u.id', '=', 'c.user_id')->whereNull('c.deleted_at')
            ->get(['c.id', 'c.user_id', 'c.status', 'u.name', 'u.email'])->keyBy('id');
        $stats = $this->svc->stats($cgs->keys()->all());
        $certs = DB::table('certifications')->where('program', config('careand_cert.program'))
            ->orderByDesc('id')->get()->groupBy('user_id');

        $rows = $cgs->map(function ($c) use ($stats, $certs) {
            $s = $stats[$c->id];
            $cert = $certs[$c->user_id][0] ?? null;   // 가장 최근 것
            return [
                'caregiver_id' => (int) $c->id,
                'name' => $c->name,
                'email' => $c->email,
                'status' => $c->status,
                'stats' => $s,
                'meets' => $this->svc->meets($s),
                'certificate' => $cert ? [
                    'number' => $cert->cert_number,
                    'issued_date' => $cert->issued_date,
                    'basis' => $cert->grant_basis,
                    'revoked_at' => $cert->revoked_at ? \App\Support\Kst::iso($cert->revoked_at) : null,
                    'revoked_reason' => $cert->revoked_reason,
                ] : null,
            ];
        });

        $held = $rows->filter(fn ($r) => $r['certificate'])->sortByDesc(fn ($r) => $r['certificate']['issued_date'])->values();
        $ready = $rows->filter(fn ($r) => !$r['certificate'] && $r['meets'] && $r['status'] === 'active')->values();
        $near = $rows->filter(fn ($r) => !$r['certificate'] && !$r['meets'] && $r['status'] === 'active'
            && ($r['stats']['sessions'] * 2 >= $criteria['min_sessions'] || $r['stats']['reviews'] * 2 >= $criteria['min_reviews']))
            ->sortByDesc(fn ($r) => $r['stats']['sessions'])->values();

        return response()->json(['success' => true, 'data' => [
            'criteria' => $criteria + ['auto_grant' => (bool) config('careand_cert.auto_grant'), 'name' => config('careand_cert.name')],
            'certified' => $held,
            'ready' => $ready,
            'near' => $near,
        ]]);
    }

    /** POST /v1/admin/caregivers/{id}/certification {note?} — 직접 부여(기준 미달이어도 가능, 사유 기록 권장) */
    public function grant(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);
        $exists = DB::table('caregivers')->where('id', $id)->whereNull('deleted_at')->where('status', 'active')->exists();
        if (!$exists) {
            return response()->json(['success' => false, 'message' => '활동 중인 돌봄전문가만 자격을 줄 수 있습니다.'], 422);
        }
        $cert = $this->svc->grant($id, 'manual', $request->user()->id, $data['note'] ?? null);
        return response()->json(['success' => true, 'message' => "자격을 부여했습니다({$cert->cert_number}).", 'data' => [
            'number' => $cert->cert_number, 'issued_date' => $cert->issued_date,
        ]]);
    }

    /** DELETE /v1/admin/caregivers/{id}/certification {reason} — 취소(인증 마크 즉시 사라짐, 자동 재부여 안 됨) */
    public function revoke(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:2', 'max:255']]);
        if (!$this->svc->revoke($id, $data['reason'], $request->user()->id)) {
            return response()->json(['success' => false, 'message' => '유효한 자격이 없습니다.'], 404);
        }
        return response()->json(['success' => true, 'message' => '자격을 취소했습니다.']);
    }
}
