<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CareandCertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 돌봄전문가 본인의 케어앤에듀 인증 자격 — 보유 시 자격증 정보, 없으면 기준 대비 진행 상황(2026-10-07).
 */
class CaregiverCertController extends Controller
{
    /** GET /v1/caregivers/me/certificate */
    public function show(Request $request, CareandCertService $svc): JsonResponse
    {
        $cg = $request->user()->caregiver;
        abort_unless($cg, 404, '돌봄전문가 등록이 필요합니다.');
        $stats = $svc->stats([$cg->id])[$cg->id];
        return response()->json(['success' => true, 'data' => [
            'certified' => ($cert = $svc->summary((int) $cg->user_id)) !== null,
            'certificate' => $cert ? $cert + ['holder' => $request->user()->name] : null,
            'name' => config('careand_cert.name'),
            'criteria' => $svc->criteria(),
            'stats' => $stats,
        ]]);
    }
}
