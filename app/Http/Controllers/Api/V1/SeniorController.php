<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Senior\StoreSeniorRequest;
use App\Http\Requests\Senior\UpdateSeniorRequest;
use App\Http\Resources\SeniorResource;
use App\Models\LtcVoucher;
use App\Models\Senior;
use App\Services\External\NhisService;
use App\Services\GeocodingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SeniorController extends Controller
{
    public function __construct(
        private NhisService $nhisService,
        private GeocodingService $geocoder,
    )
    {
    }

    /**
     * GET /v1/seniors
     * 보호자 본인의 어르신 목록
     */
    public function index(Request $request): JsonResponse
    {
        $guardian = $request->user()->guardian;

        if (!$guardian) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOT_GUARDIAN',
                'message' => '보호자 회원만 조회 가능합니다.',
            ], 403);
        }

        $seniors = Senior::where('guardian_id', $guardian->id)
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => SeniorResource::collection($seniors),
            'meta' => [
                'total' => $seniors->total(),
                'per_page' => $seniors->perPage(),
                'current_page' => $seniors->currentPage(),
                'last_page' => $seniors->lastPage(),
            ],
        ]);
    }

    /**
     * POST /v1/seniors
     * 어르신 등록 + NHIS 등급 자동 조회 + 바우처 생성
     */
    public function store(StoreSeniorRequest $request): JsonResponse
    {
        $guardian = $request->user()->guardian;

        if (!$guardian) {
            return response()->json([
                'success' => false,
                'error_code' => 'NOT_GUARDIAN',
                'message' => '보호자 회원만 등록 가능합니다.',
            ], 403);
        }

        $data = $request->validated();
        $data['guardian_id'] = $guardian->id;

        // 주소 → 좌표 자동 보정 (좌표 미입력 시)
        if (empty($data['home_lat']) && empty($data['home_lng']) && !empty($data['home_address'])) {
            if ($coords = $this->geocoder->geocode($data['home_address'])) {
                $data['home_lat'] = $coords['lat'];
                $data['home_lng'] = $coords['lng'];
            }
        }

        $senior = DB::transaction(function () use ($data) {
            $senior = Senior::create($data);

            // 장기요양인정번호가 있으면 NHIS에서 등급 자동 조회 + 바우처 생성
            if (!empty($data['care_grade_no'])) {
                try {
                    $gradeInfo = $this->nhisService->getGradeInfo($data['care_grade_no']);

                    // care_grade가 NHIS와 다르면 NHIS 우선 (정확)
                    if ($senior->care_grade !== $gradeInfo['grade']) {
                        $senior->update(['care_grade' => $gradeInfo['grade']]);
                    }

                    // 이번 달 바우처 생성
                    LtcVoucher::create([
                        'senior_id' => $senior->id,
                        'period_month' => now()->startOfMonth()->toDateString(),
                        'monthly_limit' => $gradeInfo['monthly_limit'],
                        'used_amount' => 0,
                        'remaining_amount' => $gradeInfo['monthly_limit'],
                        'copay_rate' => $gradeInfo['copay_rate'],
                    ]);
                } catch (\Throwable $e) {
                    // NHIS 조회 실패해도 등록은 진행 (수동 검증 후 갱신 가능)
                    \Log::warning('어르신 등록 시 NHIS 조회 실패', [
                        'senior_id' => $senior->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $senior;
        });

        return response()->json([
            'success' => true,
            'message' => '어르신이 등록되었습니다.',
            'data' => new SeniorResource($senior->fresh(['ltcVouchers'])),
        ], 201);
    }

    /**
     * GET /v1/seniors/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $senior = Senior::with(['guardian', 'ltcVouchers'])->findOrFail($id);

        $this->authorize('view', $senior);

        return response()->json([
            'success' => true,
            'data' => new SeniorResource($senior),
        ]);
    }

    /**
     * PATCH /v1/seniors/{id}
     */
    public function update(UpdateSeniorRequest $request, int $id): JsonResponse
    {
        $senior = Senior::findOrFail($id);

        $this->authorize('update', $senior);

        $data = $request->validated();

        // 주소가 변경되고 좌표를 직접 주지 않았으면 재지오코딩
        if (!empty($data['home_address'])
            && !array_key_exists('home_lat', $data)
            && !array_key_exists('home_lng', $data)
            && $data['home_address'] !== $senior->home_address) {
            if ($coords = $this->geocoder->geocode($data['home_address'])) {
                $data['home_lat'] = $coords['lat'];
                $data['home_lng'] = $coords['lng'];
            }
        }

        $senior->update($data);

        return response()->json([
            'success' => true,
            'message' => '어르신 정보가 수정되었습니다.',
            'data' => new SeniorResource($senior->fresh()),
        ]);
    }

    /**
     * DELETE /v1/seniors/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $senior = Senior::findOrFail($id);

        $this->authorize('delete', $senior);

        $senior->delete();

        return response()->json([
            'success' => true,
            'message' => '어르신이 삭제되었습니다.',
        ]);
    }

    /**
     * POST /v1/seniors/{id}/refresh-voucher
     * 이번 달 바우처 정보 NHIS에서 재조회
     */
    public function refreshVoucher(Request $request, int $id): JsonResponse
    {
        $senior = Senior::findOrFail($id);
        $this->authorize('update', $senior);

        if (!$senior->care_grade_no) {
            return response()->json([
                'success' => false,
                'error_code' => 'NO_LTC_NUMBER',
                'message' => '장기요양인정번호가 등록되지 않았습니다.',
            ], 422);
        }

        $periodMonth = now()->startOfMonth()->toDateString();
        $usage = $this->nhisService->getUsageStatus($senior->care_grade_no, $periodMonth);
        $gradeInfo = $this->nhisService->getGradeInfo($senior->care_grade_no);

        $voucher = LtcVoucher::updateOrCreate(
            ['senior_id' => $senior->id, 'period_month' => $periodMonth],
            [
                'monthly_limit' => $gradeInfo['monthly_limit'],
                'used_amount' => $usage['used_amount'],
                'remaining_amount' => $gradeInfo['monthly_limit'] - $usage['used_amount'],
                'copay_rate' => $gradeInfo['copay_rate'],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => '바우처 정보가 갱신되었습니다.',
            'data' => $voucher,
        ]);
    }
}
