<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Scenario;
use Tests\TestCase;

/** 관리자 회원 목록 — 산모신생아 이용 방식 필터(postpartum_mode=voucher|hourly|unset, 2026-10-11) */
class AdminMemberPostpartumModeTest extends TestCase
{
    use RefreshDatabase, Scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
    }

    public function test_filter_by_postpartum_mode(): void
    {
        [, $tok] = $this->createAdminUser();
        $mk = function (?array $prefs, string $intent = 'postpartum') {
            [$u, $g] = $this->createGuardianUser();
            $g->update(['intent' => $intent, 'preferences' => $prefs]);
            return $u->id;
        };
        $voucher = $mk(['services' => ['postpartum'], 'postpartum_mode' => 'voucher']);
        $hourly = $mk(['services' => ['childcare', 'postpartum'], 'postpartum_mode' => 'hourly'], 'childcare');
        $unsetBySvc = $mk(['services' => ['senior', 'postpartum']], 'care');
        $unsetByIntent = $mk(null);
        $other = $mk(['services' => ['childcare']], 'childcare');

        $ids = fn (string $m) => collect($this->getJson("/api/v1/admin/members?postpartum_mode={$m}&per_page=100", $this->h($tok))
            ->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        $this->assertSame([$voucher], $ids('voucher'));
        $this->assertSame([$hourly], $ids('hourly'));
        $this->assertSame(collect([$unsetBySvc, $unsetByIntent])->sort()->values()->all(), $ids('unset'));
        $this->assertNotContains($other, $ids('unset'));
    }

    public function test_admin_updates_postpartum_mode_keeping_services(): void
    {
        [, $tok] = $this->createAdminUser();
        [$u, $g] = $this->createGuardianUser();
        $g->update(['intent' => 'postpartum', 'preferences' => ['services' => ['postpartum', 'childcare']]]);

        $this->patchJson("/api/v1/admin/members/{$u->id}/postpartum-mode", ['mode' => 'voucher'], $this->h($tok))
            ->assertOk()->assertJsonPath('data.postpartum_mode', 'voucher');
        $this->assertSame(['services' => ['postpartum', 'childcare'], 'postpartum_mode' => 'voucher'], $g->fresh()->preferences);
        $u->update(['birth_date' => '1991-02-03']);
        $this->getJson("/api/v1/admin/members/{$u->id}", $this->h($tok))->assertJsonPath('data.guardian.postpartum_mode', 'voucher')
            ->assertJsonPath('data.birth_date', '1991-02-03');

        // null = 지우기(미선택)
        $this->patchJson("/api/v1/admin/members/{$u->id}/postpartum-mode", ['mode' => null], $this->h($tok))->assertOk();
        $this->assertSame(['services' => ['postpartum', 'childcare']], $g->fresh()->preferences);

        $this->patchJson("/api/v1/admin/members/{$u->id}/postpartum-mode", ['mode' => 'monthly'], $this->h($tok))->assertStatus(422);
        [$cgUser] = $this->createCaregiverUser();
        $this->patchJson("/api/v1/admin/members/{$cgUser->id}/postpartum-mode", ['mode' => 'voucher'], $this->h($tok))->assertStatus(404);
        // 회원 관리 쓰기 권한 없는 분석가는 막힘
        [, $anTok] = $this->createAdminUser('analyst');
        $this->patchJson("/api/v1/admin/members/{$u->id}/postpartum-mode", ['mode' => 'hourly'], $this->h($anTok))->assertStatus(403);
    }

    /** 앱 내 정보 — 본인이 바꾸기(PATCH guardians/me/postpartum-mode) */
    public function test_guardian_updates_own_postpartum_mode(): void
    {
        [$u, $g, $tok] = $this->createGuardianUser();
        $g->update(['intent' => 'postpartum', 'preferences' => ['services' => ['postpartum']]]);

        $this->patchJson('/api/v1/guardians/me/postpartum-mode', ['mode' => 'hourly'], $this->h($tok))
            ->assertOk()->assertJsonPath('user.guardian.preferences.postpartum_mode', 'hourly');
        $this->assertSame(['services' => ['postpartum'], 'postpartum_mode' => 'hourly'], $g->fresh()->preferences);

        $this->patchJson('/api/v1/guardians/me/postpartum-mode', ['mode' => null], $this->h($tok))->assertOk();
        $this->assertSame(['services' => ['postpartum']], $g->fresh()->preferences);
        $this->patchJson('/api/v1/guardians/me/postpartum-mode', ['mode' => 'x'], $this->h($tok))->assertStatus(422);

        [, , $cgTok] = $this->createCaregiverUser();
        $this->patchJson('/api/v1/guardians/me/postpartum-mode', ['mode' => 'voucher'], $this->h($cgTok))->assertStatus(403);
    }

    /** 파트너앱 받은 제안 — 가입 때 정부 바우처를 고른 보호자의 요청은 voucher=eligible, 아니면 null */
    public function test_caregiver_offers_carry_voucher_flag(): void
    {
        [, $cg, $cgTok] = $this->createCaregiverUser();
        [, $gv] = $this->createGuardianUser();
        $gv->update(['intent' => 'postpartum', 'preferences' => ['services' => ['postpartum'], 'postpartum_mode' => 'voucher']]);
        [, $gh] = $this->createGuardianUser();
        $gh->update(['preferences' => ['services' => ['postpartum'], 'postpartum_mode' => 'hourly']]);
        $rv = $this->makeRequest($gv);
        $rh = $this->makeRequest($gh);
        $this->makeCandidate($rv, $cg);
        $this->makeCandidate($rh, $cg);

        $rows = collect($this->getJson('/api/v1/caregivers/me/matches', $this->h($cgTok))->assertOk()->json('data'))->keyBy('request_id');
        $this->assertSame('eligible', $rows[$rv->id]['voucher']);
        $this->assertNull($rows[$rh->id]['voucher']);
        $this->assertSame([$rv->id => 'eligible'], \App\Support\GuardianPostpartumMode::voucherFlags([$rv->id, $rh->id]));
    }

    /** 관리자 요청 목록·상세·계약 일정·방문 일정 — 바우처 계약 방문(contract)·바우처 대상(eligible) 표시 */
    public function test_admin_lists_carry_voucher_flag(): void
    {
        [, $tok] = $this->createAdminUser();
        [$cgUser, $cg] = $this->createCaregiverUser();
        [$gUser, $g] = $this->createGuardianUser();
        $g->update(['intent' => 'postpartum', 'preferences' => ['services' => ['postpartum'], 'postpartum_mode' => 'voucher']]);

        $eligible = $this->makeRequest($g);
        $this->makeMatch($eligible, $cg);
        // 바우처 계약으로 생긴 방문 — 계약 1건을 최소 칸만 채워 붙인다
        $clientId = \Illuminate\Support\Facades\DB::table('postpartum_clients')->insertGetId([
            'user_id' => $gUser->id, 'name' => '산모', 'phone_encrypted' => 'x', 'birth_date' => '1995-01-01', 'address' => '서울',
            'region_code' => '11', 'delivery_date' => now()->toDateString(), 'delivery_type' => 'natural', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $contractId = \Illuminate\Support\Facades\DB::table('mnh_contracts')->insertGetId([
            'contract_no' => 'T-1', 'postpartum_client_id' => $clientId, 'user_id' => $gUser->id, 'year' => 2026, 'days' => 10,
            'start_date' => now()->toDateString(), 'payment_method' => 'cash', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $contract = $this->makeRequest($g);
        \Illuminate\Support\Facades\DB::table('match_requests')->where('id', $contract->id)->update(['mnh_contract_id' => $contractId]);   // fillable 아님(계약 생성 경로에서만 채움)
        $this->makeMatch($contract, $cg);

        $h = $this->h($tok);
        $req = collect($this->getJson('/api/v1/admin/matching/requests?per_page=100', $h)->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('eligible', $req[$eligible->id]['voucher']);
        $this->assertSame('contract', $req[$contract->id]['voucher']);
        $this->getJson("/api/v1/admin/matching/requests/{$contract->id}", $h)->assertOk()
            ->assertJsonPath('data.voucher', 'contract')->assertJsonPath('data.mnh_contract_id', $contractId);
        $this->assertSame(['contract', 'eligible'], collect($this->getJson('/api/v1/admin/contracts?per_page=100', $h)->assertOk()->json('data'))
            ->pluck('voucher')->sort()->values()->all());
        $this->assertSame(['contract', 'eligible'], collect($this->getJson('/api/v1/admin/care-sessions?per_page=100', $h)->assertOk()->json('data'))
            ->pluck('voucher')->sort()->values()->all());
    }

    /** 관리자 대시보드 KPI — 바우처 계약 건수(진행 중=확정+서비스 중, 신청 대기, 선납 대기) */
    public function test_dashboard_kpi_counts_mnh_contracts(): void
    {
        [, $tok] = $this->createAdminUser();
        [$gUser] = $this->createGuardianUser();
        $clientId = \Illuminate\Support\Facades\DB::table('postpartum_clients')->insertGetId([
            'user_id' => $gUser->id, 'name' => '산모', 'phone_encrypted' => 'x', 'birth_date' => '1995-01-01', 'address' => '서울',
            'region_code' => '11', 'delivery_date' => now()->toDateString(), 'delivery_type' => 'natural', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // 신청(미선납) · 확정(미선납) · 확정(선납) · 서비스 중 · 완료 · 취소
        foreach ([['applied', null], ['confirmed', null], ['confirmed', now()], ['active', now()], ['completed', now()], ['cancelled', null]] as $i => [$st, $paid]) {
            \Illuminate\Support\Facades\DB::table('mnh_contracts')->insert([
                'contract_no' => "T-{$i}", 'postpartum_client_id' => $clientId, 'user_id' => $gUser->id, 'year' => 2026, 'days' => 10,
                'start_date' => now()->toDateString(), 'payment_method' => 'cash', 'status' => $st, 'prepaid_at' => $paid,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        \Illuminate\Support\Facades\Cache::forget('admin:kpi');
        $this->getJson('/api/v1/admin/dashboard/kpi', $this->h($tok))->assertOk()
            ->assertJsonPath('data.mnh_contracts', ['ongoing' => 3, 'applied' => 1, 'unpaid' => 2]);
        // 카드를 누르면 가는 계약 목록 필터 — 같은 묶음·같은 숫자
        $list = fn (string $st) => $this->getJson("/api/v1/admin/mnh/contracts?status={$st}", $this->h($tok))->assertOk();
        $this->assertCount(3, $list('ongoing')->json('data'));
        $this->assertCount(2, $list('unpaid')->json('data'));
        $this->assertCount(1, $list('applied')->json('data'));
        $this->assertSame(3, $list('')->json('counts.ongoing'));
        $this->assertSame(2, $list('')->json('counts.unpaid'));
    }

    /** 관리자 회원 상세 — 생년월일 수정(돌봄전문가는 자격 정보도 맞춤, 지우기는 회원정보만), 분석가 차단 */
    public function test_admin_updates_member_birth_date(): void
    {
        [, $tok] = $this->createAdminUser();
        [$gu] = $this->createGuardianUser();
        [$cu, $cg] = $this->createCaregiverUser();
        $h = $this->h($tok);

        $this->patchJson("/api/v1/admin/members/{$gu->id}/birth-date", ['birth_date' => '1988-08-08'], $h)->assertOk();
        $this->assertSame('1988-08-08', $gu->fresh()->birth_date->format('Y-m-d'));

        $this->patchJson("/api/v1/admin/members/{$cu->id}/birth-date", ['birth_date' => '1970-01-02'], $h)->assertOk();
        $this->assertSame('1970-01-02', $cu->fresh()->birth_date->format('Y-m-d'));
        $this->assertSame('1970-01-02', (string) \Illuminate\Support\Facades\DB::table('caregivers')->where('id', $cg->id)->value('birth_date'));
        $this->getJson("/api/v1/admin/members/{$cu->id}", $h)->assertJsonPath('data.birth_date', '1970-01-02');

        $this->patchJson("/api/v1/admin/members/{$cu->id}/birth-date", ['birth_date' => null], $h)->assertOk();
        $this->assertNull($cu->fresh()->birth_date);
        $this->assertSame('1970-01-02', (string) \Illuminate\Support\Facades\DB::table('caregivers')->where('id', $cg->id)->value('birth_date'));

        $this->patchJson("/api/v1/admin/members/{$gu->id}/birth-date", ['birth_date' => now()->format('Y-m-d')], $h)->assertStatus(422);
        [, $anTok] = $this->createAdminUser('analyst');
        $this->patchJson("/api/v1/admin/members/{$gu->id}/birth-date", ['birth_date' => '1990-01-01'], $this->h($anTok))->assertStatus(403);
    }

    /** 관리자 회원 목록 — 나이대 필터(만 나이, 경계: 생일 당일에 나이가 오른다) */
    public function test_member_list_age_band_filter(): void
    {
        [, $tok] = $this->createAdminUser();
        $today = now('Asia/Seoul')->startOfDay();
        $mk = function (?string $birth) {
            [$u] = $this->createGuardianUser();
            $u->update(['birth_date' => $birth]);
            return $u->id;
        };
        $just30 = $mk($today->copy()->subYears(30)->toDateString());              // 오늘 30번째 생일 → 30대
        $almost30 = $mk($today->copy()->subYears(30)->addDay()->toDateString());  // 내일 30 → 아직 20대
        $age45 = $mk($today->copy()->subYears(45)->subMonths(3)->toDateString());
        $age72 = $mk('1950-01-01');
        $teen = $mk($today->copy()->subYears(17)->toDateString());
        $none = $mk(null);

        $ids = fn (string $b) => collect($this->getJson("/api/v1/admin/members?role=guardian&age_band={$b}&per_page=100", $this->h($tok))
            ->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$just30], $ids('30s'));
        $this->assertSame([$almost30], $ids('20s'));
        $this->assertSame([$age45], $ids('40s'));
        $this->assertSame([$age72], $ids('70plus'));
        $this->assertSame([$teen], $ids('under20'));
        $this->assertContains($none, $ids('unknown'));
        $this->assertNotContains($just30, $ids('unknown'));
    }

    /** 대시보드 나이대별 회원 분포 — 역할별, 미입력, 운영자 제외 */
    public function test_dashboard_age_distribution(): void
    {
        [$admin, $tok] = $this->createAdminUser();
        $admin->update(['birth_date' => '1980-01-01']);   // 운영자는 세지 않는다
        $today = now('Asia/Seoul')->startOfDay();
        [$g1] = $this->createGuardianUser();
        $g1->update(['birth_date' => $today->copy()->subYears(33)->toDateString()]);
        $this->createGuardianUser();   // 미입력
        [$c1] = $this->createCaregiverUser();
        $c1->update(['birth_date' => $today->copy()->subYears(55)->toDateString()]);

        $get = fn (string $role) => $this->getJson("/api/v1/admin/dashboard/age-distribution?role={$role}", $this->h($tok))->assertOk()->json('data');
        $all = $get('all');
        $by = collect($all['bands'])->pluck('count', 'key');
        $this->assertSame(1, $by['30s']);
        $this->assertSame(1, $by['50s']);
        $this->assertSame(0, $by['40s']);
        $this->assertSame(1, $all['unknown']);
        $this->assertSame(3, $all['total']);
        $this->assertSame(['under20', '20s', '30s', '40s', '50s', '60s', '70plus'], collect($all['bands'])->pluck('key')->all());
        $g = $get('guardian');
        $this->assertSame(2, $g['total']);
        $this->assertSame(0, collect($g['bands'])->pluck('count', 'key')['50s']);
        $this->assertSame(1, collect($get('caregiver')['bands'])->pluck('count', 'key')['50s']);

        // 막대를 누르면 가는 회원 목록과 인원이 같아야 한다(role=service|guardian_all|caregiver + age_band)
        $n = fn (string $q) => $this->getJson("/api/v1/admin/members?{$q}&per_page=100", $this->h($tok))->assertOk()->json('meta.total');
        $this->assertSame(1, $n('role=service&age_band=30s'));
        $this->assertSame(1, $n('role=service&age_band=unknown'));   // 운영자(생년월일 있음)·미입력 보호자 중 보호자만
        $this->assertSame(1, $n('role=guardian_all&age_band=30s'));
        $this->assertSame(0, $n('role=guardian_all&age_band=50s'));
        $this->assertSame(1, $n('role=caregiver&age_band=50s'));
    }
}
