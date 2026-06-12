<?php

namespace App\Domains\Nursing\Controllers;

use App\Domains\Nursing\Models\NursingPatient;
use App\Domains\Nursing\Requests\NursingPatientStoreRequest;
use App\Domains\Nursing\Requests\NursingPatientUpdateRequest;
use App\Domains\Nursing\Resources\NursingPatientResource;
use App\Http\Controllers\Controller;
use App\Services\GeocodingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 간병 환자 관리 (보호자 소유)
 *
 * Endpoints:
 *  GET    /v1/nursing/patients
 *  POST   /v1/nursing/patients
 *  GET    /v1/nursing/patients/{id}
 *  PATCH  /v1/nursing/patients/{id}
 *  DELETE /v1/nursing/patients/{id}
 */
class NursingPatientController extends Controller
{
    public function __construct(private GeocodingService $geocoder)
    {
    }

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

        $patients = NursingPatient::where('guardian_id', $guardian->id)
            ->orderByDesc('created_at')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => NursingPatientResource::collection($patients),
            'meta' => [
                'total' => $patients->total(),
                'per_page' => $patients->perPage(),
                'current_page' => $patients->currentPage(),
                'last_page' => $patients->lastPage(),
            ],
        ]);
    }

    public function store(NursingPatientStoreRequest $request): JsonResponse
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

        // 병원 주소 → 좌표 자동 보정 (체크인 검증에 필요)
        if (empty($data['hospital_lat']) && empty($data['hospital_lng'])) {
            if ($coords = $this->geocoder->geocode($data['hospital_address'])) {
                $data['hospital_lat'] = $coords['lat'];
                $data['hospital_lng'] = $coords['lng'];
            }
        }

        $patient = NursingPatient::create($data);

        return response()->json([
            'success' => true,
            'message' => '환자가 등록되었습니다.',
            'data' => new NursingPatientResource($patient),
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $patient = NursingPatient::findOrFail($id);
        $this->authorize('view', $patient);

        return response()->json([
            'success' => true,
            'data' => new NursingPatientResource($patient),
        ]);
    }

    public function update(NursingPatientUpdateRequest $request, int $id): JsonResponse
    {
        $patient = NursingPatient::findOrFail($id);
        $this->authorize('update', $patient);

        $data = $request->validated();

        // 주소가 바뀌었는데 좌표가 안 왔으면 재지오코딩
        if (!empty($data['hospital_address'])
            && $data['hospital_address'] !== $patient->hospital_address
            && empty($data['hospital_lat'])) {
            if ($coords = $this->geocoder->geocode($data['hospital_address'])) {
                $data['hospital_lat'] = $coords['lat'];
                $data['hospital_lng'] = $coords['lng'];
            }
        }

        $patient->update($data);

        return response()->json([
            'success' => true,
            'message' => '환자 정보가 수정되었습니다.',
            'data' => new NursingPatientResource($patient->fresh()),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $patient = NursingPatient::findOrFail($id);
        $this->authorize('delete', $patient);

        $hasOpenRequest = $patient->matchRequests()
            ->whereIn('status', ['open', 'matching', 'matched'])
            ->exists();
        if ($hasOpenRequest) {
            return response()->json([
                'success' => false,
                'error_code' => 'HAS_ACTIVE_REQUEST',
                'message' => '진행 중인 매칭 요청이 있어 삭제할 수 없습니다.',
            ], 422);
        }

        $patient->delete();

        return response()->json([
            'success' => true,
            'message' => '환자가 삭제되었습니다.',
        ]);
    }
}
