<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Caregiver\RegisterCaregiverRequest;
use App\Http\Resources\CaregiverResource;
use App\Models\Caregiver;
use App\Services\External\MohwService;
use App\Services\GeocodingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CaregiverController extends Controller
{
    public function __construct(
        private MohwService $mohwService,
        private GeocodingService $geocoder,
    ) {
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

        // 근거지 주소 → 좌표 자동 보정 (좌표 미입력 시) — 매칭 거리 랭킹에 필요
        if (empty($data['base_lat']) && empty($data['base_lng']) && !empty($data['base_address'])) {
            if ($coords = $this->geocoder->geocode($data['base_address'])) {
                $data['base_lat'] = $coords['lat'];
                $data['base_lng'] = $coords['lng'];
            }
        }

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

            // 3단계: 기관 초대로 가입한 경우 — 전화번호 매칭 pending 초대 → 소속 자동 연결
            $invite = \App\Models\CaregiverInvite::where('phone', $user->phone)
                ->where('status', 'pending')
                ->orderByDesc('created_at')
                ->first();
            if ($invite) {
                $caregiver->update(['org_id' => $invite->org_id]);
                $invite->update([
                    'status' => 'accepted',
                    'accepted_caregiver_id' => $caregiver->id,
                    'accepted_at' => now(),
                ]);
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
            // 역경매: 표준 희망 시급 / 자동입찰 설정
            'default_rate' => ['nullable', 'numeric', 'min:0'],
            'auto_bid' => ['nullable', 'boolean'],
        ]);

        // 주소가 바뀌었는데 좌표를 직접 안 줬으면 재지오코딩 (매칭 거리 랭킹 유지)
        if (array_key_exists('base_address', $validated)
            && !empty($validated['base_address'])
            && empty($validated['base_lat'])
            && empty($validated['base_lng'])
            && $validated['base_address'] !== $caregiver->base_address) {
            if ($coords = $this->geocoder->geocode($validated['base_address'])) {
                $validated['base_lat'] = $coords['lat'];
                $validated['base_lng'] = $coords['lng'];
            }
        }

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
            ->leftJoin('service_addresses as sa', 'sa.id', '=', 'r.service_address_id')
            ->where('mc.caregiver_id', $caregiver->id)
            ->select(
                'mc.id', 'mc.rank', 'mc.ai_score', 'mc.ai_reasons', 'mc.response',
                'mc.bid_hourly', 'mc.bid_note', 'mc.bid_status',
                'r.id as request_id', 'r.service_domain', 'r.mode',
                'r.scheduled_start', 'r.duration_min', 'r.status as request_status',
                'r.price_estimate',
                \Illuminate\Support\Facades\DB::raw('COALESCE(s.name, np.name, sa.label) as senior_name')
            )
            ->orderByDesc('mc.created_at')
            ->get()
            ->map(function ($r) {
                $est = $r->price_estimate ? json_decode($r->price_estimate, true) : null;

                return [
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
                    // 역경매 입찰 (입찰 화면용)
                    'bid_hourly' => $r->bid_hourly !== null ? (float) $r->bid_hourly : null,
                    'bid_note' => $r->bid_note,
                    'bid_status' => $r->bid_status,
                    'price_guide' => $est ? [
                        'floor' => $est['floor'] ?? null,
                        'suggested' => $est['suggested'] ?? null,
                        'ceil' => $est['ceil'] ?? null,
                    ] : null,
                ];
            });

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
            ->leftJoin('service_addresses as sa', 'sa.id', '=', 'r.service_address_id')
            ->where('m.caregiver_id', $caregiver->id)
            ->select(
                'cs.id', 'cs.status', 'cs.actual_start', 'cs.actual_end',
                'cs.duration_min',
                \Illuminate\Support\Facades\DB::raw('COALESCE(cs.scheduled_start, m.scheduled_start) as scheduled_start'),
                \Illuminate\Support\Facades\DB::raw('COALESCE(cs.scheduled_end, m.scheduled_end) as scheduled_end'),
                'r.service_domain',
                'r.requirements',
                \Illuminate\Support\Facades\DB::raw('COALESCE(s.name, np.name, sa.label) as senior_name')
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
                'photo_required' => (bool) (json_decode($r->requirements ?? '', true)['photo_required'] ?? false),
            ]);

        return response()->json(['success' => true, 'data' => $rows]);
    }

    /**
     * GET /v1/caregivers/recommended
     * 보호자 홈 '가까운 추천 인력' 피드 — 활성 인력 중 평점/실적 상위,
     * 보호자 최근 요청의 대상자 위치 기준으로 거리순 정렬(위치 없으면 평점순).
     */
    public function recommended(Request $request): JsonResponse
    {
        $guardian = $request->user()->guardian;

        // 거리 기준점: 보호자 최근 요청의 대상자 위치(없으면 거리 null)
        $origin = null;
        if ($guardian) {
            $origin = \Illuminate\Support\Facades\DB::table('match_requests as r')
                ->leftJoin('seniors as s', 's.id', '=', 'r.senior_id')
                ->leftJoin('nursing_patients as np', 'np.id', '=', 'r.nursing_patient_id')
                ->leftJoin('service_addresses as sa', 'sa.id', '=', 'r.service_address_id')
                ->where('r.guardian_id', $guardian->id)
                ->orderByDesc('r.id')
                ->selectRaw('COALESCE(s.home_lat, np.hospital_lat, sa.lat) as lat, COALESCE(s.home_lng, np.hospital_lng, sa.lng) as lng')
                ->first();
        }
        $oLat = $origin && $origin->lat !== null ? (float) $origin->lat : null;
        $oLng = $origin && $origin->lng !== null ? (float) $origin->lng : null;

        // 도메인별 최저 기준 시급
        $rates = \Illuminate\Support\Facades\DB::table('service_categories')
            ->where('is_active', 1)
            ->selectRaw('domain, MIN(base_rate) as rate')
            ->groupBy('domain')
            ->pluck('rate', 'domain');

        $rows = \Illuminate\Support\Facades\DB::table('caregivers as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('c.status', 'active')
            ->whereNull('c.deleted_at')
            ->orderByDesc('c.rating_avg')
            ->orderByDesc('c.completed_sessions')
            ->limit(20)
            ->get(['c.id', 'u.name', 'c.gender', 'c.specialties', 'c.service_domains', 'c.base_lat', 'c.base_lng', 'c.rating_avg', 'c.rating_count', 'c.completed_sessions', 'c.career_track', 'c.license_verified_at']);

        $data = $rows->map(function ($c) use ($rates, $oLat, $oLng) {
            $domains = $c->service_domains ? explode(',', $c->service_domains) : [];
            $primary = $domains[0] ?? 'senior';
            $rate = isset($rates[$primary]) ? (int) $rates[$primary] : null;

            $dist = null;
            if ($oLat !== null && $c->base_lat !== null) {
                $dist = round($this->haversineKm($oLat, $oLng, (float) $c->base_lat, (float) $c->base_lng), 1);
            }

            $tag = null;
            if (in_array($c->career_track, ['premium', 'instructor'], true)) {
                $tag = 'BEST';
            } elseif ($c->career_track === 'excellent') {
                $tag = '우수';
            } elseif ($c->license_verified_at) {
                $tag = '인증';
            }

            return [
                'id' => (int) $c->id,
                'name' => $c->name,
                'rating' => number_format((float) $c->rating_avg, 1),
                'rating_count' => (int) $c->rating_count,
                'completed_sessions' => (int) $c->completed_sessions,
                'spec' => $this->specLabel($c->specialties, $primary),
                'base_rate' => $rate,
                'distance_km' => $dist,
                'tag' => $tag,
            ];
        })->values();

        // 위치를 아는 경우 거리 오름차순(거리 미상은 뒤로), 상위 8명
        if ($oLat !== null) {
            $data = $data->sortBy(fn ($x) => $x['distance_km'] ?? 99999)->values();
        }
        $data = $data->take(8)->values();

        return response()->json(['success' => true, 'data' => $data]);
    }

    private function specLabel(?string $specialtiesJson, string $domain): string
    {
        $map = [
            'nursing_hospital' => '병원 간병', 'hk_cleaning' => '가사 청소',
            'hk_repair' => '가사 수리', 'hk_organizing' => '정리수납',
        ];
        $domLabel = ['senior' => '시니어 돌봄', 'nursing' => '간병', 'housekeeping' => '가사', 'postpartum' => '산후 케어'];
        $arr = json_decode($specialtiesJson ?? '[]', true);
        if (is_array($arr) && count($arr) > 0) {
            $labels = array_map(fn ($s) => $map[strtolower((string) $s)] ?? $s, $arr);
            return implode('·', array_slice($labels, 0, 2));
        }
        return ($domLabel[$domain] ?? '돌봄') . ' 전문';
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
