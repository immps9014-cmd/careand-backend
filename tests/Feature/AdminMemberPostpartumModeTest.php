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
        $this->getJson("/api/v1/admin/members/{$u->id}", $this->h($tok))->assertJsonPath('data.guardian.postpartum_mode', 'voucher');

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
}
