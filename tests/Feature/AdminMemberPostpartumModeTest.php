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
}
