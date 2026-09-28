<?php

namespace Tests;

use App\Models\CareMatch;
use App\Models\CareSession;
use App\Models\Caregiver;
use App\Models\Guardian;
use App\Models\MatchRequest;
use App\Models\Senior;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * S6 시험 공용 도우미 (2026-09-29) — 요청·후보·매칭·세션·일지를 짧게 만든다.
 * 외부 HTTP 는 막는다(preventStrayRequests). AI 서비스가 필요한 시험은 fakeAi() 로 응답을 넣는다.
 */
trait Scenario
{
    use AuthHelpers;

    protected function setUpScenario(): void
    {
        Http::preventStrayRequests();
        $this->ensureCategories();
    }

    /**
     * 인증 헤더 + 가드 초기화 — 한 시험에서 여러 사용자로 요청하면 JWT 가드가 앞 사용자를 기억한다(S6 에서 확인).
     * S6 시험은 authHeaders() 대신 이것을 쓴다.
     */
    protected function h(string $token, array $extra = []): array
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('tymon.jwt');
        $this->app->forgetInstance('tymon.jwt.auth');
        \Tymon\JWTAuth\Facades\JWTAuth::clearResolvedInstances();
        \Illuminate\Support\Facades\Auth::clearResolvedInstances();
        return array_merge(['Authorization' => "Bearer {$token}"], $extra);
    }

    /** AI 서비스 가짜 응답 — 후보 추천은 빈 목록(규칙 폴백), 일지는 주어진 검증 결과 */
    protected function fakeAi(array $verification = null, string $guardian = '오늘 식사를 절반 정도 드셨어요.'): void
    {
        $v = $verification ?? ['risk' => 0.0, 'claims' => 0, 'unsupported' => [], 'sensitive' => [], 'alerts' => [], 'needs_review' => false, 'reasons' => [], 'method' => 'test'];
        $log = ['guardian_version' => $guardian, 'medical_version' => '식사 50%', 'categorized' => ['식사·수분' => ['식사 절반 정도']],
            'confidence' => 1 - $v['risk'], 'model' => 'test', 'verification' => $v];
        Http::fake([
            '*/care-log/chips/catalog' => Http::response(['chips' => [['code' => 'MealHalf', 'label' => '식사 절반 정도']], 'source' => 'test']),
            '*/care-log/chips' => Http::response($log),
            '*/care-log/generate' => Http::response($log),
            '*/ai/match/recommend' => Http::response(['candidates' => []]),
            '*' => Http::response(['success' => false], 503),
        ]);
    }

    protected function makeRequest(Guardian $guardian, ?Senior $senior = null, array $over = []): MatchRequest
    {
        $senior ??= $this->createSeniorFor($guardian);
        return MatchRequest::create(array_merge([
            'guardian_id' => $guardian->id, 'senior_id' => $senior->id, 'service_domain' => 'senior',
            'category_id' => DB::table('service_categories')->value('id'), 'mode' => 'normal',
            'scheduled_start' => now()->addDays(2)->setTime(1, 0), 'duration_min' => 120, 'status' => 'open',
        ], $over));
    }

    protected function makeCandidate(MatchRequest $req, Caregiver $cg, array $over = []): int
    {
        return DB::table('match_candidates')->insertGetId(array_merge([
            'request_id' => $req->id, 'caregiver_id' => $cg->id, 'ai_score' => 0.9, 'ai_reasons' => json_encode(['시험']),
            'rank' => 1, 'response' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ], $over));
    }

    /** 확정 매칭 + 세션 1개(상태 지정) */
    protected function makeMatch(MatchRequest $req, Caregiver $cg, string $sessionStatus = 'scheduled', ?Carbon $start = null): array
    {
        $start ??= Carbon::parse($req->scheduled_start);
        $req->update(['status' => 'matched', 'matched_at' => now()]);
        $m = CareMatch::create([
            'request_id' => $req->id, 'caregiver_id' => $cg->id, 'scheduled_start' => $start,
            'scheduled_end' => $start->copy()->addMinutes($req->duration_min), 'hourly_rate' => 18000,
            'estimated_amount' => 36000, 'status' => $sessionStatus === 'completed' ? 'completed' : ($sessionStatus === 'in_progress' ? 'in_progress' : 'confirmed'),
        ]);
        $s = CareSession::create([
            'match_id' => $m->id, 'scheduled_start' => $start, 'scheduled_end' => $start->copy()->addMinutes($req->duration_min),
            'status' => $sessionStatus,
            'actual_start' => in_array($sessionStatus, ['in_progress', 'completed'], true) ? now()->subHours(2) : null,
            'actual_end' => $sessionStatus === 'completed' ? now() : null,
            'duration_min' => $sessionStatus === 'completed' ? 120 : null,
        ]);
        return [$m, $s];
    }

    protected function makeSummary(CareSession $s, string $guardianText = '오늘 산책을 함께 했어요. 기분이 좋으셨어요.'): int
    {
        return DB::table('ai_log_summaries')->insertGetId([
            'session_id' => $s->id, 'guardian_version' => $guardianText, 'medical_version' => '산책', 'categorized' => json_encode(['활동' => ['산책']]),
            'confidence' => 0.9, 'llm_model' => 'test', 'generated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function notifications(string $type): \Illuminate\Support\Collection
    {
        return DB::table('notifications')->where('type', $type)->get();
    }
}
