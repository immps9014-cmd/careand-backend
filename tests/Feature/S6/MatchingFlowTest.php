<?php

namespace Tests\Feature\S6;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Scenario;
use Tests\TestCase;

/**
 * 통합시험 IT-02 매칭 흐름 · 단위시험 — REQ-F-02·03·10·11·18·21 (S6, 2026-09-29)
 */
class MatchingFlowTest extends TestCase
{
    use RefreshDatabase, Scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
        $this->fakeAi();
    }

    /** UT-10 지정 → 응답 시한 기록 + 돌봄전문가 지정 알림 → 수락 → 매칭·세션 생성 + 보호자 확정 알림 */
    public function test_select_then_accept_creates_match_and_notifies(): void
    {
        [, $guardian, $gTok] = $this->createGuardianUser();
        [$cgUser, $cg, $cTok] = $this->createCaregiverUser();
        $req = $this->makeRequest($guardian);
        $cid = $this->makeCandidate($req, $cg);

        $this->postJson("/api/v1/matching/requests/{$req->id}/select", ["candidate_id" => $cid], $this->h($gTok))->assertOk();
        $cand = DB::table('match_candidates')->find($cid);
        $this->assertNotNull($cand->offer_expires_at, '응답 마감 시각');
        $this->assertSame('matching', $req->fresh()->status);
        $this->assertTrue($this->notifications('MATCH_REQUEST_ASSIGNED')->contains('user_id', $cgUser->id), '지정 알림');

        $this->postJson("/api/v1/matching/candidates/{$cid}/accept", [], $this->h($cTok))->assertOk();
        $this->assertSame('matched', $req->fresh()->status);
        $this->assertSame(1, DB::table('matches')->where('request_id', $req->id)->count());
        $this->assertSame(1, DB::table('care_sessions')->count());
        $this->assertSame(1, $this->notifications('MATCH_CONFIRMED')->count(), '보호자 확정 알림');
    }

    /** UT-21 같은 돌봄전문가의 겹치는 일정은 수락 409, 맞닿는 일정은 허용 */
    public function test_schedule_conflict_blocks_accept(): void
    {
        [, $guardian] = $this->createGuardianUser();
        [, $cg, $cTok] = $this->createCaregiverUser();
        $first = $this->makeRequest($guardian);
        $this->makeMatch($first, $cg);
        $overlap = $this->makeRequest($guardian, null, ['scheduled_start' => $first->scheduled_start->copy()->addHour()]);
        $cid = $this->makeCandidate($overlap, $cg);
        $this->postJson("/api/v1/matching/candidates/{$cid}/accept", [], $this->h($cTok))
            ->assertStatus(409)->assertJson(['error_code' => 'SCHEDULE_CONFLICT']);

        $adjacent = $this->makeRequest($guardian, null, ['scheduled_start' => $first->scheduled_start->copy()->addMinutes(120)]);
        $cid2 = $this->makeCandidate($adjacent, $cg);
        $this->postJson("/api/v1/matching/candidates/{$cid2}/accept", [], $this->h($cTok))->assertOk();
    }

    /** UT-10 지정 후 무응답 → matching:watch 가 자동 거절·요청 재개·보호자 알림(두 번 돌려도 한 번) */
    public function test_offer_timeout_expires_candidate_once(): void
    {
        [$gUser, $guardian] = $this->createGuardianUser();
        [, $cg] = $this->createCaregiverUser();
        $req = $this->makeRequest($guardian, null, ['status' => 'matching']);
        $cid = $this->makeCandidate($req, $cg, ['offered_at' => now()->subMinutes(6), 'offer_expires_at' => now()->subMinute()]);

        $this->artisan('matching:watch')->assertSuccessful();
        $this->artisan('matching:watch')->assertSuccessful();
        $this->assertSame('expired', DB::table('match_candidates')->where('id', $cid)->value('response'));
        $this->assertSame('open', $req->fresh()->status);
        $this->assertSame(1, $this->notifications('MATCH_OFFER_TIMEOUT')->where('user_id', $gUser->id)->count());
    }

    /** UT-18·11 6시간 미매칭 관리자 알림 1회, 24시간 전 리마인더 양쪽 1회 */
    public function test_unmatched_alert_and_reminder(): void
    {
        [$admin] = $this->createAdminUser();
        [$gUser, $guardian] = $this->createGuardianUser();
        [$cgUser, $cg] = $this->createCaregiverUser();
        $stale = $this->makeRequest($guardian);
        DB::table('match_requests')->where('id', $stale->id)->update(['created_at' => now()->subHours(7)]);
        $soon = $this->makeRequest($guardian, null, ['scheduled_start' => now()->addHours(5)]);
        $this->makeMatch($soon, $cg);

        $this->artisan('matching:watch');
        $this->artisan('matching:watch');
        $this->assertSame(1, $this->notifications('MATCH_UNMATCHED_ALERT')->where('user_id', $admin->id)->count());
        $rem = $this->notifications('CARE_REMINDER');
        $this->assertSame(2, $rem->count());
        $this->assertEqualsCanonicalizing([$gUser->id, $cgUser->id], $rem->pluck('user_id')->all());
    }

    /** UT-18 운영자 수동 매칭 — 충돌 시 409, force 로 강행 */
    public function test_manual_assign_conflict_requires_force(): void
    {
        [, $aTok] = $this->createAdminUser();
        [, $guardian] = $this->createGuardianUser();
        [, $cg] = $this->createCaregiverUser();
        $first = $this->makeRequest($guardian);
        $this->makeMatch($first, $cg);
        $req = $this->makeRequest($guardian, null, ['scheduled_start' => $first->scheduled_start]);
        $h = $this->h($aTok, ['X-Access-Reason' => 'test']);
        $this->postJson("/api/v1/admin/matching/requests/{$req->id}/manual-assign", ['caregiver_id' => $cg->id], $h)->assertStatus(409);
        $this->postJson("/api/v1/admin/matching/requests/{$req->id}/manual-assign", ['caregiver_id' => $cg->id, 'force' => true], $h)->assertOk();
    }
}
