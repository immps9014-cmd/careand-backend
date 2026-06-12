<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Caregiver\RegisterCaregiverRequest;
use App\Http\Resources\CaregiverResource;
use App\Models\Caregiver;
use App\Services\External\MohwService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CaregiverController extends Controller
{
    public function __construct(private MohwService $mohwService)
    {
    }

    /**
     * GET /v1/caregivers/me
     */
    public function me(Request $request): JsonResponse
    {
        $caregiver = $request->user()->caregiver;

        if (!$caregiver) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOT_REGISTERED',
                'message' => '인력 등록이 완료되지 않았습니다.',
                'next_step' => 'caregiver_register',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => new CaregiverResource($caregiver->load(['user', 'organization'])),
        ]);
    }

    /**
     * POST /v1/caregivers/register
     * 인력 추가 정보 등록 + 자격증 OCR 결과 + 진위확인
     */
    public function register(RegisterCaregiverRequest $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'caregiver') {
            return response()->json([
                'success' => false,
                'error_code' => 'INVALID_ROLE',
                'message' => '인력 회원만 등록 가능합니다.',
            ], 403);
        }

        if ($user->caregiver) {
            return response()->json([
                'success' => false,
                'error_code' => 'ALREADY_REGISTERED',
                'message' => '이미 등록된 인력입니다.',
            ], 409);
        }

        $data = $request->validated();
        $data['user_id'] = $user->id;

        // 활동 도메인 (SET 컬럼은 콤마 문자열)
        $data['service_domains'] = implode(',', $data['service_domains'] ?? ['senior']);

        $caregiver = DB::transaction(function () use ($data, $user) {
            // 1단계: caregivers 테이블 INSERT (status=pending)
            $caregiver = Caregiver::create(array_merge($data, [
                'rating_avg' => 0,
                'rating_count' => 0,
                'completed_sessions' => 0,
                'grade_level' => 1,
                'status' => 'pending',
            ]));

            // 2단계: 보건복지부 자격증 진위확인 (자격증 제출자만 — 무자격 도메인은 관리자 수동 승인)
            if ($caregiver->license_no) {
                try {
                    $verification = $this->mohwService->verifyLicense(
                        licenseNo: $caregiver->license_no,
                        name: $user->name,
                        birthDate: $caregiver->birth_date->toDateString(),
                    );

                    if ($verification['valid']) {
                        $caregiver->update([
                            'license_verified_at' => now(),
                        ]);
                    } else {
                        $caregiver->update([
                            'status' => 'rejected',
                            'rejection_reason' => '보건복지부 자격 진위확인 실패',
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::warning('자격 진위확인 실패 - 수동 검수 필요', [
                        'caregiver_id' => $caregiver->id,
                        'error' => $e->getMessage(),
                    ]);
                    // 자격 확인 실패해도 등록은 유지 (관리자가 수동 검수)
                }
            }

            return $caregiver;
        });

        return response()->json([
            'success' => true,
            'message' => $caregiver->status === 'rejected'
                ? '자격 확인에 실패했습니다. 자격증 정보를 다시 확인해주세요.'
                : '등록이 접수되었습니다. 관리자 검수 후 활성화됩니다.',
            'data' => new CaregiverResource($caregiver),
        ], 201);
    }

    /**
     * PATCH /v1/caregivers/me/profile
     * 인력 본인 프로필 수정 (특기, 근거지 등)
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $caregiver = $request->user()->caregiver;

        if (!$caregiver) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOT_REGISTERED',
                'message' => '인력 등록이 필요합니다.',
            ], 404);
        }

        $validated = $request->validate([
            'specialties' => ['nullable', 'array'],
            'specialties.*' => ['string', 'max:50'],
            'base_address' => ['nullable', 'string', 'max:255'],
            'base_lat' => ['nullable', 'numeric', 'between:-90,90'],
            'base_lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $caregiver->update($validated);

        return response()->json([
            'success' => true,
            'message' => '프로필이 수정되었습니다.',
            'data' => new CaregiverResource($caregiver->fresh()),
        ]);
    }

    /**
     * GET /v1/caregivers/{id}
     * 인력 상세 (보호자가 매칭 후보 클릭 시)
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $caregiver = Caregiver::with(['user', 'organization'])
            ->where('status', 'active')
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new CaregiverResource($caregiver),
        ]);
    }

    /**
     * POST /v1/caregivers/me/leave
     * 휴직 요청
     */
    public function requestLeave(Request $request): JsonResponse
    {
        $caregiver = $request->user()->caregiver;

        if (!$caregiver || $caregiver->status !== 'active') {
            return response()->json([
                'success' => false,
                'error_code' => 'INVALID_STATUS',
                'message' => '활성 상태의 인력만 휴직 신청 가능합니다.',
            ], 422);
        }

        $caregiver->update(['status' => 'leave']);

        return response()->json([
            'success' => true,
            'message' => '휴직 처리되었습니다. 진행중인 매칭은 정상 수행해주세요.',
        ]);
    }

    /**
     * POST /v1/caregivers/me/return
     * 복귀
     */
    public function requestReturn(Request $request): JsonResponse
    {
        $caregiver = $request->user()->caregiver;

        if (!$caregiver || $caregiver->status !== 'leave') {
            return response()->json([
                'success' => false,
                'error_code' => 'INVALID_STATUS',
                'message' => '휴직 상태가 아닙니다.',
            ], 422);
        }

        $caregiver->update(['status' => 'active']);

        return response()->json([
            'success' => true,
            'message' => '복귀 처리되었습니다.',
        ]);
    }

    /**
     * GET /v1/caregivers/me/matches
     * 내게 추천된 매칭 후보 (수락 대기 포함)
     */
    public function myMatches(Request $request): JsonResponse
    {
        $caregiver = $request->user()->caregiver;
        if (!$caregiver) {
            return response()->json(['success' => false, 'message' => '인력 회원만 조회 가능합니다.'], 403);
        }

        $rows = \Illuminate\Support\Facades\DB::table('match_candidates as mc')
            ->join('match_requests as r', 'r.id', '=', 'mc.request_id')
            ->leftJoin('seniors as s', 's.id', '=', 'r.senior_id')
            ->leftJoin('nursing_patients as np', 'np.id', '=', 'r.nursing_patient_id')
            ->where('mc.caregiver_id', $caregiver->id)
            ->select(
                'mc.id', 'mc.rank', 'mc.ai_score', 'mc.ai_reasons', 'mc.response',
                'r.id as request_id', 'r.service_domain', 'r.mode',
                'r.scheduled_start', 'r.duration_min', 'r.status as request_status',
                \Illuminate\Support\Facades\DB::raw('COALESCE(s.name, np.name) as senior_name')
            )
            ->orderByDesc('mc.created_at')
            ->get()
            ->map(fn ($r) => [
                'candidate_id' => $r->id,
                'rank' => $r->rank,
                'ai_score' => (float) $r->ai_score,
                'ai_reasons' => $r->ai_reasons ? json_decode($r->ai_reasons, true) : [],
                'response' => $r->response,
                'request_id' => $r->request_id,
                'service_domain' => $r->service_domain,
                'mode' => $r->mode,
                'scheduled_start' => $r->scheduled_start,
                'duration_min' => $r->duration_min,
                'request_status' => $r->request_status,
                'senior_name' => $r->senior_name ?? '(미상)',
            ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * GET /v1/caregivers/me/sessions
     * 내 케어 세션(일정)
     */
    public function mySessions(Request $request): JsonResponse
    {
        $caregiver = $request->user()->caregiver;
        if (!$caregiver) {
            return response()->json(['success' => false, 'message' => '인력 회원만 조회 가능합니다.'], 403);
        }

        $rows = \Illuminate\Support\Facades\DB::table('care_sessions as cs')
            ->join('matches as m', 'm.id', '=', 'cs.match_id')
            ->leftJoin('match_requests as r', 'r.id', '=', 'm.request_id')
            ->leftJoin('seniors as s', 's.id', '=', 'r.senior_id')
            ->leftJoin('nursing_patients as np', 'np.id', '=', 'r.nursing_patient_id')
            ->where('m.caregiver_id', $caregiver->id)
            ->select(
                'cs.id', 'cs.status', 'cs.actual_start', 'cs.actual_end',
                'cs.duration_min',
                \Illuminate\Support\Facades\DB::raw('COALESCE(cs.scheduled_start, m.scheduled_start) as scheduled_start'),
                \Illuminate\Support\Facades\DB::raw('COALESCE(cs.scheduled_end, m.scheduled_end) as scheduled_end'),
                'r.service_domain',
                \Illuminate\Support\Facades\DB::raw('COALESCE(s.name, np.name) as senior_name')
            )
            ->orderByDesc('m.scheduled_start')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status,
                'service_domain' => $r->service_domain,
                'senior_name' => $r->senior_name ?? '(미상)',
                'scheduled_start' => $r->scheduled_start,
                'scheduled_end' => $r->scheduled_end,
                'actual_start' => $r->actual_start,
                'actual_end' => $r->actual_end,
                'duration_min' => $r->duration_min,
            ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }
}
