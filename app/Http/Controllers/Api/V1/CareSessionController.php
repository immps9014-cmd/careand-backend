<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CareSession\CheckinRequest;
use App\Http\Requests\CareSession\StoreActivityRequest;
use App\Http\Requests\CareSession\UploadVoiceLogRequest;
use App\Http\Resources\CareSessionResource;
use App\Jobs\GenerateCareLogJob;
use App\Jobs\ProcessVoiceLogJob;
use App\Models\AttendanceLog;
use App\Models\CareActivity;
use App\Models\CarePhoto;
use App\Models\CareSession;
use App\Models\VoiceLog;
use App\Services\External\AiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CareSessionController extends Controller
{
    public function __construct(private AiService $aiService)
    {
    }

    /**
     * GET /v1/care-sessions/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $session = CareSession::with([
            'match.caregiver.user:id,name',
            'match.request.senior:id,name',
            'attendanceLogs',
            'activities',
            'voiceLogs',
            'aiSummaries',
            'photos',
        ])->findOrFail($id);

        $this->authorize('view', $session);

        return response()->json([
            'success' => true,
            'data' => new CareSessionResource($session),
        ]);
    }

    /**
     * POST /v1/care-sessions/{id}/checkin
     * 인력 GPS 출근 체크
     */
    public function checkin(CheckinRequest $request, int $id): JsonResponse
    {
        $session = CareSession::with('match.request.senior')->findOrFail($id);

        $this->authorizeAsCaregiver($request, $session);

        if ($session->status !== 'scheduled') {
            return response()->json([
                'success' => false,
                'error_code' => 'INVALID_STATUS',
                'message' => "이미 진행 중이거나 완료된 세션입니다. (현재: {$session->status})",
            ], 422);
        }

        $data = $request->validated();
        $matchRequest = $session->match->request;

        $location = $matchRequest->recipientLocation();
        if (!$location) {
            return response()->json([
                'success' => false,
                'error_code' => 'NO_RECIPIENT_LOCATION',
                'message' => '서비스 장소의 좌표가 등록되어 있지 않습니다. 관리자에게 문의해주세요.',
            ], 422);
        }
        $radius = $matchRequest->checkinRadiusMeters();

        // 서비스 장소와의 거리 계산 (Haversine)
        $distance = $this->calculateDistance(
            $data['lat'], $data['lng'],
            $location[0], $location[1]
        );

        $isValid = $distance <= $radius;

        DB::transaction(function () use ($session, $data, $distance, $isValid) {
            AttendanceLog::create([
                'session_id' => $session->id,
                'event_type' => 'checkin',
                'lat' => $data['lat'],
                'lng' => $data['lng'],
                'distance_m' => $distance,
                'accuracy_m' => $data['accuracy'] ?? null,
                'is_valid' => $isValid,
                'logged_at' => now(),
            ]);

            if ($isValid) {
                $session->update([
                    'actual_start' => now(),
                    'status' => 'in_progress',
                ]);
            }
        });

        if (!$isValid) {
            return response()->json([
                'success' => false,
                'error_code' => 'GPS_TOO_FAR',
                'message' => sprintf(
                    '서비스 장소에서 너무 멀리 떨어져 있습니다. (거리: %dm, 허용: %dm 이내)',
                    round($distance), $radius
                ),
                'distance_m' => round($distance, 2),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => '체크인 완료. 케어를 시작합니다.',
            'data' => [
                'session_id' => $session->id,
                'distance_m' => round($distance, 2),
                'started_at' => $session->actual_start->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /v1/care-sessions/{id}/checkout
     */
    public function checkout(Request $request, int $id): JsonResponse
    {
        $session = CareSession::findOrFail($id);
        $this->authorizeAsCaregiver($request, $session);

        if ($session->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'error_code' => 'INVALID_STATUS',
                'message' => '진행 중인 세션이 아닙니다.',
            ], 422);
        }

        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        // 보호자가 완료사진을 요구한 요청(가사 등)은 사진 없이 체크아웃 불가
        $session->loadMissing('match.request');
        if (($session->match->request->requirements['photo_required'] ?? false)
            && !$session->photos()->exists()) {
            return response()->json([
                'success' => false,
                'error_code' => 'PHOTO_REQUIRED',
                'message' => '작업 완료 사진을 1장 이상 등록해야 체크아웃할 수 있습니다.',
            ], 422);
        }

        DB::transaction(function () use ($session, $validated) {
            AttendanceLog::create([
                'session_id' => $session->id,
                'event_type' => 'checkout',
                'lat' => $validated['lat'],
                'lng' => $validated['lng'],
                'distance_m' => 0,
                'is_valid' => true,
                'logged_at' => now(),
            ]);

            $duration = now()->diffInMinutes($session->actual_start);
            $session->update([
                'actual_end' => now(),
                'duration_min' => $duration,
                'status' => 'completed',
            ]);

            // 인력 통계 업데이트
            $session->match->caregiver->increment('completed_sessions');
        });

        // C:Writer AI 일지 자동 생성 (비동기)
        GenerateCareLogJob::dispatch($session->id);

        return response()->json([
            'success' => true,
            'message' => '체크아웃 완료. 수고하셨습니다.',
            'data' => [
                'duration_min' => $session->fresh()->duration_min,
                'ended_at' => $session->fresh()->actual_end->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /v1/care-sessions/{id}/activities
     * 활동 기록 (식사·복약·운동 등)
     */
    public function storeActivity(StoreActivityRequest $request, int $id): JsonResponse
    {
        $session = CareSession::findOrFail($id);
        $this->authorizeAsCaregiver($request, $session);

        $activity = CareActivity::create(array_merge(
            $request->validated(),
            ['session_id' => $session->id, 'performed_at' => now()]
        ));

        return response()->json([
            'success' => true,
            'data' => $activity,
        ], 201);
    }

    /**
     * POST /v1/care-sessions/{id}/voice-log
     * 음성 일지 업로드 → STT/LLM 처리 큐 등록
     */
    public function uploadVoiceLog(UploadVoiceLogRequest $request, int $id): JsonResponse
    {
        $session = CareSession::findOrFail($id);
        $this->authorizeAsCaregiver($request, $session);

        $data = $request->validated();

        $voiceLog = VoiceLog::create([
            'session_id' => $session->id,
            'audio_url' => $data['audio_url'],
            'duration_sec' => $data['duration_sec'],
            'status' => 'uploaded',
        ]);

        // 비동기 STT + LLM 처리 큐 등록 (워커가 처리; QUEUE=sync 환경에선 즉시 실행)
        ProcessVoiceLogJob::dispatch($voiceLog->id);

        return response()->json([
            'success' => true,
            'message' => '음성이 업로드되었습니다. AI 분석 중입니다.',
            'data' => [
                'voice_log_id' => $voiceLog->id,
                'estimated_complete_sec' => 30,
                'status' => $voiceLog->status,
            ],
        ], 202);
    }

    /**
     * POST /v1/care-sessions/{id}/photos
     */
    public function uploadPhoto(Request $request, int $id): JsonResponse
    {
        $session = CareSession::findOrFail($id);
        $this->authorizeAsCaregiver($request, $session);

        // 인력 앱(케어플로우)에서 직접 촬영한 파일 업로드 경로와,
        // 기존 photo_url(이미 호스팅된 URL) 경로를 모두 지원한다.
        if ($request->hasFile('file')) {
            $validated = $request->validate([
                'file' => ['required', 'file', 'mimes:jpeg,jpg,png,webp', 'max:10240'],
                'caption' => ['nullable', 'string', 'max:200'],
            ]);

            $path = $request->file('file')->store("care-photos/{$session->id}", 'public');
            $photoUrl = Storage::disk('public')->url($path);
            $thumbnailUrl = null;
        } else {
            $validated = $request->validate([
                'photo_url' => ['required', 'url'],
                'thumbnail_url' => ['nullable', 'url'],
                'caption' => ['nullable', 'string', 'max:200'],
            ]);
            $photoUrl = $validated['photo_url'];
            $thumbnailUrl = $validated['thumbnail_url'] ?? null;
        }

        $photo = CarePhoto::create([
            'session_id' => $session->id,
            'photo_url' => $photoUrl,
            'thumbnail_url' => $thumbnailUrl,
            'caption' => $validated['caption'] ?? null,
            'taken_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $photo,
        ], 201);
    }

    /**
     * GET /v1/care-sessions/{id}/ai-summary
     */
    public function getAiSummary(Request $request, int $id): JsonResponse
    {
        $session = CareSession::with('aiSummaries')->findOrFail($id);
        $this->authorize('view', $session);

        $summary = $session->aiSummaries()->latest()->first();

        if (!$summary) {
            return response()->json([
                'success' => false,
                'error_code' => 'SUMMARY_NOT_READY',
                'message' => 'AI 요약이 아직 준비되지 않았습니다.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'guardian_version' => $summary->guardian_version,
                'medical_version' => $summary->medical_version,
                'categorized' => $summary->categorized,
                'confidence' => $summary->confidence,
                'generated_at' => $summary->generated_at->toIso8601String(),
            ],
        ]);
    }

    // ===== Private helpers =====

    /**
     * Haversine 공식으로 두 좌표 간 거리(미터) 계산
     */
    private function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000; // 미터
        $latFrom = deg2rad($lat1);
        $latTo = deg2rad($lat2);
        $lngDiff = deg2rad($lng2 - $lng1);
        $latDiff = deg2rad($lat2 - $lat1);

        $a = sin($latDiff / 2) ** 2
            + cos($latFrom) * cos($latTo) * sin($lngDiff / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    private function authorizeAsCaregiver(Request $request, CareSession $session): void
    {
        $caregiver = $request->user()->caregiver;
        if (!$caregiver || $session->match->caregiver_id !== $caregiver->id) {
            abort(403, '본인의 케어 세션만 접근 가능합니다.');
        }
    }
}
