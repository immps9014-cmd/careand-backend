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
}
