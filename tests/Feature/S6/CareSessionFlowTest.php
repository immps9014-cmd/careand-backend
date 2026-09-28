<?php

namespace Tests\Feature\S6;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Scenario;
use Tests\TestCase;

/**
 * 통합시험 IT-03 돌봄 수행 → 일지 → 보호자 · 단위시험 — REQ-F-05·06·12·14·22·29·40·41 (S6, 2026-09-29)
 */
class CareSessionFlowTest extends TestCase
{
    use RefreshDatabase, Scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
    }

    private function scene(string $status = 'scheduled'): array
    {
        [$gUser, $guardian, $gTok] = $this->createGuardianUser();
        [$cUser, $cg, $cTok] = $this->createCaregiverUser();
        $req = $this->makeRequest($guardian);
        [$m, $s] = $this->makeMatch($req, $cg, $status);
        return compact('gUser', 'guardian', 'gTok', 'cUser', 'cg', 'cTok', 'req', 'm', 's');
    }

    /** UT-12 출근: 반경 안 정상 / 반경 밖 3km 이내 수락+운영팀 경고 / 3km 초과 거부 */
    public function test_checkin_radius_rules(): void
    {
        $this->fakeAi();
        [$admin] = $this->createAdminUser();
        $x = $this->scene();
        $home = [37.5663, 126.9019];   // SeniorFactory 좌표
        $this->postJson("/api/v1/care-sessions/{$x['s']->id}/checkin", ['lat' => $home[0] + 0.05, 'lng' => $home[1]], $this->h($x['cTok']))
            ->assertStatus(422)->assertJson(['error_code' => 'GPS_TOO_FAR']);
        $this->postJson("/api/v1/care-sessions/{$x['s']->id}/checkin", ['lat' => $home[0] + 0.01, 'lng' => $home[1]], $this->h($x['cTok']))
            ->assertOk()->assertJson(['out_of_range' => true]);
        $this->assertSame(1, DB::table('attendance_logs')->where('out_of_range', 1)->count());
        $this->assertSame(1, $this->notifications('ATTENDANCE_OUT_OF_RANGE')->where('user_id', $admin->id)->count());
        $this->assertSame(1, $this->notifications('CARE_STARTED')->where('user_id', $x['gUser']->id)->count(), '보호자 출근 알림');
    }

    /** UT-40·41·22 칩 기록 → 퇴근 → 칩 일지 → 안전 알림이면 검수 대기 + 보호자·관리자 즉시 알림 */
    public function test_chips_checkout_safety_alert_routes_to_review(): void
    {
        $this->fakeAi(['risk' => 0.0, 'claims' => 1, 'unsupported' => [], 'sensitive' => [], 'needs_review' => true, 'method' => 'test+chips',
            'alerts' => [['id' => 'Alert_Fall', 'label' => '낙상', 'severity' => 'high', 'evidence' => '칩: 낙상']], 'reasons' => ['안전 알림: 낙상']]);
        [$admin] = $this->createAdminUser();
        $x = $this->scene('in_progress');
        $this->putJson("/api/v1/care-sessions/{$x['s']->id}/chips", ['chips' => ['MealHalf', 'ChipFall'], 'note' => '오후 3시'], $this->h($x['cTok']))->assertOk();
        $this->assertNotSame('오후 3시', DB::table('care_sessions')->where('id', $x['s']->id)->value('journal_note'), '메모 암호화 저장');

        $this->postJson("/api/v1/care-sessions/{$x['s']->id}/checkout", ['lat' => 37.5663, 'lng' => 126.9019], $this->h($x['cTok']))->assertOk();
        $s = DB::table('care_sessions')->find($x['s']->id);
        $this->assertSame('pending', $s->review_status);
        $this->assertNull($s->log_sent_at);
        $this->assertNotNull($s->log_started_at, 'KPI 3 작성 시작(퇴근 전 칩 → 퇴근 시각)');
        $this->assertSame(1, DB::table('ai_log_summaries')->where('session_id', $s->id)->count());
        $this->assertSame(2, $this->notifications('SAFETY_ALERT')->whereIn('user_id', [$admin->id, $x['gUser']->id])->count());
        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), '/care-log/chips') && $r['chips'] === ['MealHalf', 'ChipFall']);
    }

    /** UT-29 위험 없는 일지는 자동 승인·보호자 전송(KPI 3 전송 시각) */
    public function test_no_risk_log_auto_approved(): void
    {
        $this->fakeAi();
        $x = $this->scene('in_progress');
        $this->postJson("/api/v1/care-sessions/{$x['s']->id}/checkout", ['lat' => 37.5663, 'lng' => 126.9019], $this->h($x['cTok']))->assertOk();
        $s = DB::table('care_sessions')->find($x['s']->id);
        $this->assertSame('approved', $s->review_status);
        $this->assertNotNull($s->log_sent_at);
        $this->assertSame(1, $this->notifications('CARE_SUMMARY_READY')->where('user_id', $x['gUser']->id)->count());
        $this->assertSame(1, $this->notifications('CARE_COMPLETED')->where('user_id', $x['gUser']->id)->count());
        $this->assertSame(1, $this->notifications('REVIEW_REQUEST')->where('user_id', $x['gUser']->id)->count(), '마지막 회차 → 후기 요청');
    }

    /** UT-14·22 인력 수정 → 검수 대기, 운영자 수정 → AI 원본 보관, 전송 후 인력 수정 409, 남의 세션 403 */
    public function test_log_edit_by_caregiver_and_admin(): void
    {
        [, $aTok] = $this->createAdminUser();
        $x = $this->scene('completed');
        $this->makeSummary($x['s'], 'AI 가 만든 원래 문장입니다.');
        [, , $otherTok] = $this->createCaregiverUser();

        $this->putJson("/api/v1/care-sessions/{$x['s']->id}/log", ['guardian_version' => '다른 사람이 고치는 문장입니다.'], $this->h($otherTok))->assertStatus(403);
        $this->putJson("/api/v1/care-sessions/{$x['s']->id}/log", ['guardian_version' => '식사를 절반 드셨고 산책했어요.', 'reason' => '정정'], $this->h($x['cTok']))->assertOk();
        $this->assertSame('pending', DB::table('care_sessions')->where('id', $x['s']->id)->value('review_status'));
        $this->patchJson("/api/v1/admin/care-logs/{$x['s']->id}", ['guardian_version' => '운영자가 다듬은 문장입니다.', 'reason' => '문장 정리'], $this->h($aTok, ['X-Access-Reason' => 'test']))->assertOk();
        $row = DB::table('ai_log_summaries')->where('session_id', $x['s']->id)->first();
        $this->assertSame('AI 가 만든 원래 문장입니다.', $row->guardian_original);
        $this->assertSame('admin', $row->edited_role);

        DB::table('care_sessions')->where('id', $x['s']->id)->update(['review_status' => 'approved', 'log_sent_at' => now()]);
        $this->putJson("/api/v1/care-sessions/{$x['s']->id}/log", ['guardian_version' => '전송 뒤에 고치려는 문장입니다.'], $this->h($x['cTok']))->assertStatus(409);
    }

    /** UT-05 보호자 일지(사진·체크리스트) + 가족 공유 링크: 생성·로그인 없이 열람(이름 가림)·끄기·만료, 남의 일지 403 */
    public function test_guardian_log_and_family_share(): void
    {
        $x = $this->scene('completed');
        $this->makeSummary($x['s'], '홍어머님께서 오늘 산책을 하셨어요.');
        DB::table('care_photos')->insert(['session_id' => $x['s']->id, 'photo_url' => 'https://x/p.jpg', 'created_at' => now(), 'updated_at' => now()]);
        [, , $otherTok] = $this->createGuardianUser();

        $this->postJson("/api/v1/care-sessions/{$x['s']->id}/share", [], $this->h($x['gTok']))->assertStatus(422);   // 미승인
        DB::table('care_sessions')->where('id', $x['s']->id)->update(['review_status' => 'approved']);
        $this->getJson("/api/v1/care-sessions/{$x['s']->id}/ai-summary", $this->h($x['gTok']))->assertOk()->assertJsonCount(1, 'data.photos');
        $this->postJson("/api/v1/care-sessions/{$x['s']->id}/share", [], $this->h($otherTok))->assertStatus(403);
        $url = $this->postJson("/api/v1/care-sessions/{$x['s']->id}/share", [], $this->h($x['gTok']))->assertCreated()->json('data.url');
        $token = substr($url, -40);
        $this->assertSame(0, DB::table('care_log_shares')->where('token_hash', $token)->count(), '토큰 원문 미저장');

        $pub = $this->getJson("/api/v1/public/care-logs/{$token}")->assertOk();
        $this->assertStringNotContainsString('홍어머님', $pub->json('data.guardian_version'));
        $this->assertArrayNotHasKey('photos', $pub->json('data'));

        $shareId = $this->getJson("/api/v1/care-sessions/{$x['s']->id}/shares", $this->h($x['gTok']))->json('data.0.id');
        $this->deleteJson("/api/v1/care-log-shares/{$shareId}", [], $this->h($otherTok))->assertStatus(404);
        $this->deleteJson("/api/v1/care-log-shares/{$shareId}", [], $this->h($x['gTok']))->assertOk();
        $this->getJson("/api/v1/public/care-logs/{$token}")->assertStatus(404);
    }
}
