<?php

namespace Tests\Feature\S6;

use App\Models\Senior;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tests\Scenario;
use Tests\TestCase;

/**
 * 통합시험 IT-06 관리자·정보보호 — REQ-F-01·17·19·25·26 · REQ-N-05·06·07·08·09 (S6, 2026-09-29)
 */
class AdminSecurityTest extends TestCase
{
    use RefreshDatabase, Scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
    }

    /** UT-N07 2단계 인증 전 토큰은 관리자 API 401, 권한 5단계: 분석가는 대시보드만·블랙리스트 등록 불가 */
    public function test_admin_mfa_and_rbac(): void
    {
        [$admin] = $this->createAdminUser();
        $noMfa = $this->issueToken($admin);   // mfa 없이
        $this->getJson('/api/v1/admin/dashboard/kpi', $this->h($noMfa))->assertStatus(401)->assertJson(['error_code' => 'MFA_REQUIRED']);

        [, $anTok] = $this->createAdminUser('analyst');
        [$u] = $this->createGuardianUser();
        $this->getJson('/api/v1/admin/dashboard/breakdown?period=month', $this->h($anTok))->assertOk();
        $this->postJson('/api/v1/admin/blacklist', ['user_id' => $u->id, 'reason' => '분석가 시도입니다'], $this->h($anTok))->assertStatus(403);
        $this->getJson('/api/v1/admin/members', $this->h($anTok))->assertStatus(403);
    }

    /** UT-N08 관리자 개인정보 조회는 사유와 함께 감사로그, 해시 체인 검증 통과 */
    public function test_audit_log_chain(): void
    {
        [, $aTok] = $this->createAdminUser();
        [$u] = $this->createGuardianUser();
        $this->getJson("/api/v1/admin/members/{$u->id}", $this->h($aTok, ['X-Access-Reason' => rawurlencode('민원 확인')]))->assertOk();
        $log = DB::table('audit_logs')->orderByDesc('id')->first();
        $this->assertSame('민원 확인', $log->reason);
        $this->assertNotEmpty($log->hash);
        $this->artisan('audit:verify')->assertSuccessful();
    }

    /** UT-N09·F-19 회원 CSV — 슈퍼관리자·사유 필수·마스킹 */
    public function test_member_export_requires_reason_and_masks(): void
    {
        [, $aTok] = $this->createAdminUser();
        [, $brTok] = $this->createAdminUser('branch');
        [$u] = $this->createGuardianUser();
        $this->get('/api/v1/admin/exports/members', $this->h($aTok, ['Accept' => 'application/json']))->assertStatus(422);
        $this->get('/api/v1/admin/exports/members', $this->h($brTok, ['Accept' => 'application/json', 'X-Access-Reason' => rawurlencode('월간 점검용')]))->assertStatus(403);
        $csv = $this->get('/api/v1/admin/exports/members', $this->h($aTok, ['X-Access-Reason' => rawurlencode('월간 점검용')]))->assertOk()->streamedContent();
        $this->assertStringNotContainsString($u->phone, $csv);
        $this->assertStringNotContainsString($u->email, $csv);
    }

    /** UT-19 블랙리스트 → 정지·인증번호 차단 → 해제 */
    public function test_blacklist_blocks_resignup(): void
    {
        [, $aTok] = $this->createAdminUser();
        [$u] = $this->createGuardianUser();
        $this->postJson('/api/v1/admin/blacklist', ['user_id' => $u->id, 'reason' => '반복 노쇼와 폭언'], $this->h($aTok))->assertCreated();
        $this->assertSame('suspended', $u->fresh()->status);
        $this->assertSame(0, DB::table('member_blacklist')->where('phone_hash', $u->phone)->count(), '번호 원문 미저장');
        $this->postJson('/api/v1/auth/otp/send', ['phone' => $u->phone])->assertStatus(403)->assertJson(['error_code' => 'BLACKLISTED']);
        $this->postJson('/api/v1/auth/login', ['email' => $u->email, 'password' => 'password'])->assertStatus(403);
        $id = DB::table('member_blacklist')->value('id');
        $this->postJson("/api/v1/admin/blacklist/{$id}/release", ['reason' => '소명 확인'], $this->h($aTok))->assertOk();
        $this->assertSame('active', $u->fresh()->status);
        $this->postJson('/api/v1/auth/otp/send', ['phone' => $u->phone])->assertOk();
    }

    /** UT-25 공지 도메인 대상 — 해당 도메인 인력만, 이력에 도달·24시간 열람 */
    public function test_announcement_domain_targeting(): void
    {
        [, $aTok] = $this->createAdminUser();
        [$senCg, $c1] = $this->createCaregiverUser();
        DB::table('caregivers')->where('id', $c1->id)->update(['service_domains' => 'senior']);
        [$ppCg, $c2] = $this->createCaregiverUser();
        DB::table('caregivers')->where('id', $c2->id)->update(['service_domains' => 'postpartum']);
        $this->postJson('/api/v1/admin/announcements', ['title' => '산후 공지', 'body' => '산후 도메인 안내', 'target' => 'caregiver', 'domain' => 'postpartum'], $this->h($aTok))->assertOk();
        $this->assertSame([$ppCg->id], DB::table('notifications')->where('type', 'announcement')->pluck('user_id')->all());
        $row = $this->getJson('/api/v1/admin/announcements', $this->h($aTok))->json('data.0');
        $this->assertSame(1, $row['recipients']);
        $this->assertArrayHasKey('read_24h_rate', $row);
    }

    /** UT-17 대시보드 지점·도메인 필터 */
    public function test_dashboard_breakdown_filters(): void
    {
        [, $aTok] = $this->createAdminUser();
        $d = $this->getJson('/api/v1/admin/dashboard/breakdown?period=week&domain=senior', $this->h($aTok))->assertOk()->json('data');
        $this->assertSame('senior', $d['filter']['domain']);
        $this->assertCount(7, $d['by_domain']);
    }

    /** UT-N05 자유 입력 의료정보는 DB 에 암호문, 모델로는 평문 */
    public function test_medical_fields_encrypted_at_rest(): void
    {
        [, $guardian] = $this->createGuardianUser();
        $s = Senior::factory()->create(['guardian_id' => $guardian->id, 'special_notes' => '인슐린 하루 2회']);
        $this->assertStringNotContainsString('인슐린', (string) DB::table('seniors')->where('id', $s->id)->value('special_notes'));
        $this->assertSame('인슐린 하루 2회', $s->fresh()->special_notes);
    }

    /** UT-01 고정 인증번호 123456 은 테스트 번호(010-0000-)만 */
    public function test_stub_otp_only_for_test_numbers(): void
    {
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '01000001234', 'code' => '123456'])->assertOk();
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => '01098765432', 'code' => '123456'])->assertStatus(422);
    }

    /** UT-N06 자동 파기 dry-run 은 아무것도 바꾸지 않는다 */
    public function test_privacy_purge_dry_run(): void
    {
        [$u] = $this->createGuardianUser();
        DB::table('users')->where('id', $u->id)->update(['deleted_at' => now()->subDays(40)]);
        $this->artisan('privacy:purge')->assertSuccessful();
        $this->assertSame($u->name, DB::table('users')->where('id', $u->id)->value('name'));
        $this->artisan('privacy:purge', ['--execute' => true])->assertSuccessful();
        $this->assertSame('탈퇴회원', DB::table('users')->where('id', $u->id)->value('name'));
    }

    /** UT-33 소셜 로그인 키가 없으면 제공자 목록이 비어 버튼이 숨는다 */
    public function test_oauth_providers_hidden_without_keys(): void
    {
        config(['services.oauth.kakao.client_id' => '', 'services.oauth.google.client_id' => '']);
        $this->getJson('/api/v1/auth/oauth/providers')->assertOk()->assertExactJson(['success' => true, 'data' => ['kakao' => false, 'google' => false]]);
    }
}
