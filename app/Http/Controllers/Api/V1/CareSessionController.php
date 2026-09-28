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
use App\Services\NotificationService;
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
            'match.request.serviceAddress:id,address',
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
        $isManual = (bool) ($session->match->is_manual ?? false);

        $location = $matchRequest->recipientLocation();
        if (!$isManual && !$location) {
            return response()->json([
                'success' => false,
                'error_code' => 'NO_RECIPIENT_LOCATION',
                'message' => '서비스 장소의 좌표가 등록되어 있지 않습니다. 관리자에게 문의해주세요.',
            ], 422);
        }
        $radius = $matchRequest->checkinRadiusMeters();

        // 서비스 장소와의 거리 계산 (Haversine). 수동매칭은 거리 제한 미적용(자동매칭만 반영).
        $distance = $location ? $this->calculateDistance(
            $data['lat'], $data['lng'],
            $location[0], $location[1]
        ) : 0;

        // 반경 안 → 정상 / 반경 밖이지만 허용 한도 안 → 받되 운영팀 경고(기능 12) / 한도 밖 → 거부
        $hardLimit = (int) config('matching_rules.attendance_hard_limit_m', 3000);
        $outOfRange = !$isManual && $distance > $radius;
        $isValid = $isManual ? true : ($distance <= max($radius, $hardLimit));

        DB::transaction(function () use ($session, $data, $distance, $isValid, $outOfRange) {
            AttendanceLog::create([
                'session_id' => $session->id,
                'event_type' => 'checkin',
                'lat' => $data['lat'],
                'lng' => $data['lng'],
                'distance_m' => $distance,
                'accuracy_m' => $data['accuracy'] ?? null,
                'is_valid' => $isValid,
                'out_of_range' => $isValid && $outOfRange,
                'logged_at' => now(),
            ]);

            if ($isValid) {
                $session->update([
                    'actual_start' => now(),
                    'status' => 'in_progress',
                ]);
                // 케어 시작 → 매칭도 진행중으로 전이(진행 파이프라인 '케어시작' 단계).
                if ($session->match->status === 'confirmed') {
                    $session->match->update(['status' => 'in_progress']);
                }
            }
        });

        if (!$isValid) {
            return response()->json([
                'success' => false,
                'error_code' => 'GPS_TOO_FAR',
                'message' => sprintf(
                    '서비스 장소에서 너무 멀리 떨어져 있습니다. (거리: %dm, 허용: %dm 이내) 장소에 도착한 뒤 다시 시도해 주세요.',
                    round($distance), max($radius, $hardLimit)
                ),
                'distance_m' => round($distance, 2),
            ], 422);
        }
        $this->notifyGuardian($session->match_id, NotificationService::TYPE_CARE_STARTED, ['session_id' => $session->id]);
        if ($outOfRange) {
            $this->alertOutOfRange($session, 'checkin', $distance, $radius);
        }

        return response()->json([
            'success' => true,
            'message' => $outOfRange
                ? sprintf('체크인 완료. 서비스 장소에서 %dm 떨어져 있어 운영팀에 확인 요청이 전달됐어요.', round($distance))
                : '체크인 완료. 케어를 시작합니다.',
            'out_of_range' => $outOfRange,
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

        // 퇴근 위치도 거리를 남긴다 — 반경 밖이면 운영팀 경고(기능 12). 퇴근은 막지 않는다(케어 시간 산정이 우선)
        $loc = $session->match->request->recipientLocation();
        $outDistance = $loc ? $this->calculateDistance((float) $validated['lat'], (float) $validated['lng'], $loc[0], $loc[1]) : 0;
        $outRadius = $session->match->request->checkinRadiusMeters();
        $outOut = $loc && !($session->match->is_manual ?? false) && $outDistance > $outRadius;

        DB::transaction(function () use ($session, $validated, $outDistance, $outOut) {
            AttendanceLog::create([
                'session_id' => $session->id,
                'event_type' => 'checkout',
                'lat' => $validated['lat'],
                'lng' => $validated['lng'],
                'distance_m' => min($outDistance, 999999),
                'is_valid' => true,
                'out_of_range' => $outOut,
                'logged_at' => now(),
            ]);

            $duration = now()->diffInMinutes($session->actual_start);
            // KPI 「케어일지 작성시간」: 케어 중 이미 활동·음성·사진 기록이 있으면 퇴근 시각이 작성 시작
            $hasLogInput = $session->activities()->exists()
                || $session->voiceLogs()->exists()
                || $session->photos()->exists()
                || !empty($session->journal_chips);
            $session->update([
                'actual_end' => now(),
                'duration_min' => $duration,
                'status' => 'completed',
                'log_started_at' => $hasLogInput ? now() : null,
            ]);

            // 케어 완료 → 매칭 상태 전이. 반복(recurring) 요청은 모든 세션이 끝나야 완료,
            // 남은 세션이 있으면 진행중 유지(진행 파이프라인 '케어완료'/'케어시작' 판정).
            $match = $session->match;
            $hasRemaining = CareSession::where('match_id', $match->id)
                ->where('status', '!=', 'completed')
                ->exists();
            $match->update(['status' => $hasRemaining ? 'in_progress' : 'completed']);

            // 인력 통계 업데이트
            $match->caregiver->increment('completed_sessions');
        });

        if ($outOut) {
            $this->alertOutOfRange($session, 'checkout', $outDistance, $outRadius);
        }

        // C:Writer AI 일지 자동 생성 (비동기)
        GenerateCareLogJob::dispatch($session->id);
        $this->notifyGuardian($session->match_id, NotificationService::TYPE_CARE_COMPLETED,
            ['session_id' => $session->id, 'duration_min' => (int) $session->fresh()->duration_min]);
        // 매칭의 마지막 회차가 끝나면 후기 요청(기능 7) — 정기 요청은 회차마다가 아니라 한 번만
        if ($session->match->fresh()->status === 'completed') {
            $this->notifyGuardian($session->match_id, NotificationService::TYPE_REVIEW_REQUEST, ['match_id' => $session->match_id]);
        }

        return response()->json([
            'success' => true,
            'message' => $outOut
                ? sprintf('체크아웃 완료. 서비스 장소에서 %dm 떨어져 있어 운영팀에 확인 요청이 전달됐어요.', round($outDistance))
                : '체크아웃 완료. 수고하셨습니다.',
            'out_of_range' => $outOut,
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

        $session->markLogStarted();   // KPI: 퇴근 후 첫 기록이면 일지 작성 시작
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
    public function uploadVoiceLog(Request $request, int $id): JsonResponse
    {
        $session = CareSession::findOrFail($id);
        $this->authorizeAsCaregiver($request, $session);

        // 인력 앱(브라우저 녹음)에서 직접 업로드한 오디오 파일과,
        // 기존 audio_url(이미 호스팅된 URL) 경로를 모두 지원한다.
        if ($request->hasFile('file')) {
            $validated = $request->validate([
                'file' => ['required', 'file', 'max:51200'], // 최대 50MB
                'duration_sec' => ['required', 'integer', 'between:1,1800'],
            ]);
            // 음성은 보호자에게 노출되지 않고 STT 처리에만 쓰이므로 비공개(local) 디스크에 저장.
            // AI 서비스가 같은 서버에서 절대경로로 직접 읽어 헤어핀 다운로드를 피한다
            // (ai-service _fetch_audio 의 os.path.isfile 분기).
            $path = $request->file('file')->store("voice-logs/{$session->id}", 'local');
            $audioUrl = Storage::disk('local')->path($path);
            $durationSec = (int) $validated['duration_sec'];
        } else {
            $validated = $request->validate([
                'audio_url' => ['required', 'url', 'max:500'],
                'duration_sec' => ['required', 'integer', 'between:1,1800'],
            ]);
            $audioUrl = $validated['audio_url'];
            $durationSec = (int) $validated['duration_sec'];
        }

        $session->markLogStarted();   // KPI: 퇴근 후 첫 기록이면 일지 작성 시작
        $voiceLog = VoiceLog::create([
            'session_id' => $session->id,
            'audio_url' => $audioUrl,
            'duration_sec' => $durationSec,
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

        $session->markLogStarted();   // KPI: 퇴근 후 첫 기록이면 일지 작성 시작
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

        $isGuardian = $request->user()->isGuardian();

        // INV-6: 보호자는 검수 '승인'된 일지만 열람
        if ($isGuardian && $session->review_status !== 'approved') {
            return response()->json([
                'success' => false,
                'error_code' => 'SUMMARY_NOT_APPROVED',
                'message' => 'AI 요약이 아직 준비되지 않았습니다.',
            ], 404);
        }

        $summary = $session->aiSummaries()->latest()->first();

        if (!$summary) {
            return response()->json([
                'success' => false,
                'error_code' => 'SUMMARY_NOT_READY',
                'message' => 'AI 요약이 아직 준비되지 않았습니다.',
            ], 404);
        }

        $data = [
            'guardian_version' => $summary->guardian_version,
            'categorized' => $summary->categorized,
            // 일지와 함께 보는 돌봄 사진(기능 5) — 최대 5장
            'photos' => $session->photos()->orderBy('id')->limit(5)->get(['photo_url', 'thumbnail_url', 'caption'])
                ->map(fn ($p) => ['url' => $p->photo_url, 'thumbnail' => $p->thumbnail_url ?: $p->photo_url, 'caption' => $p->caption])->values(),
            'confidence' => $summary->confidence,
            'generated_at' => $summary->generated_at->toIso8601String(),
        ];
        // INV-7: medical_version 은 보호자에게 노출하지 않음(인력/관리자만)
        if (!$isGuardian) {
            $data['medical_version'] = $summary->medical_version;
            // 돌봄전문가 검토용(기능 14): 전송 여부·검수 사유·수정 가능 여부
            $data['review_status'] = $session->review_status;
            $data['sent'] = $session->log_sent_at !== null;
            $data['editable'] = $session->log_sent_at === null && $session->review_status !== 'approved';
            $data['review_note'] = $session->review_note;
            $data['edited_at'] = $summary->edited_at;
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    // ===== Private helpers =====

    /**
     * Haversine 공식으로 두 좌표 간 거리(미터) 계산
     */
    /**
     * PUT /v1/care-sessions/{id}/log {guardian_version, reason?} — 돌봄전문가 일지 검토·수정(기능 14)
     * 보호자에게 가기 전만. 고친 일지는 원문 대조를 거치지 않았으므로 운영자 검수로 넘어간다.
     */
    public function updateLog(Request $request, int $id): JsonResponse
    {
        $session = CareSession::with('match')->findOrFail($id);
        $this->authorizeAsCaregiver($request, $session);
        $v = $request->validate(['guardian_version' => 'required|string|min:10|max:5000', 'reason' => 'nullable|string|max:255']);
        if ($session->log_sent_at !== null || $session->review_status === 'approved') {
            return response()->json(['success' => false, 'error_code' => 'LOG_SENT', 'message' => '이미 보호자에게 전송된 일지예요. 고칠 내용이 있으면 운영팀에 알려 주세요.'], 409);
        }
        $r = app(\App\Services\CareLogEditService::class)->edit($id, $request->user()->id, 'caregiver', $v['guardian_version'], null, $v['reason'] ?? null);
        return response()->json(['success' => $r['ok'], 'error_code' => $r['code'] ?? null, 'message' => $r['message']], $r['ok'] ? 200 : 404);
    }

    /** 반경 밖 출퇴근 → 케어 진행 담당 관리자 알림(기능 12) */
    private function alertOutOfRange(CareSession $session, string $event, float $distance, int $radius): void
    {
        $svc = app(NotificationService::class);
        $ctx = $svc->matchContext((int) $session->match_id);
        foreach ($svc->adminsFor('care-sessions') as $adminId) {
            $svc->notifySafely($adminId, NotificationService::TYPE_ATTENDANCE_OUT_OF_RANGE, [
                'session_id' => $session->id, 'event' => $event, 'distance_m' => (int) round($distance), 'radius_m' => $radius,
                'caregiver_name' => $ctx?->caregiver_name, 'recipient_name' => $ctx?->recipient_name,
            ]);
        }
    }

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

    /** 출근·퇴근 → 보호자 앱 알림 + 알림톡 CAREN_CARE_START/END (기능 6·12, 2026-09-28 S4 — 이전엔 발송 안 됨) */
    private function notifyGuardian(int $matchId, string $type, array $extra): void
    {
        $svc = app(NotificationService::class);
        $ctx = $svc->matchContext($matchId);
        if (!$ctx) {
            return;
        }
        $svc->notifySafely((int) $ctx->guardian_user_id, $type, $extra + [
            'caregiver_name' => $ctx->caregiver_name,
            'recipient_name' => $ctx->recipient_name,
            'senior_name' => $ctx->recipient_name,
            'time' => now('Asia/Seoul')->format('H:i'),
        ]);
    }
}
