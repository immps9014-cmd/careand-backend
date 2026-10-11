<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Scenario;
use Tests\TestCase;

/** 다운로드 기록(2026-10-11) — 성공(export.*)·거부(미들웨어 4xx) 한 목록, 성공 건 중복 없음, 슈퍼관리자만 */
class AdminExportLogTest extends TestCase
{
    use RefreshDatabase, Scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
    }

    public function test_export_logs(): void
    {
        [$admin, $tok] = $this->createAdminUser();
        $admin->update(['name' => '관리자갑']);
        $this->createGuardianUser();
        $ok = ['X-Access-Reason' => rawurlencode('10월 회원 현황 보고')];
        $this->get('/api/v1/admin/exports/members?role=guardian', $this->h($tok, $ok))->assertOk();
        $this->get('/api/v1/admin/exports/list/matching?status=open', $this->h($tok, $ok))->assertOk();
        $this->getJson('/api/v1/admin/exports/payments', $this->h($tok))->assertStatus(422);   // 사유 없음 → 거부 기록

        $j = $this->getJson('/api/v1/admin/exports/logs?period=today', $this->h($tok))->assertOk()->json();
        $this->assertSame(2, $j['summary']['success']);
        $this->assertSame(1, $j['summary']['blocked']);
        $rows = collect($j['data']);
        $this->assertCount(3, $rows);   // 성공 건의 미들웨어 기록은 중복으로 안 나온다
        $m = $rows->firstWhere('kind', 'members');
        $this->assertSame('회원 목록', $m['kind_label']);
        $this->assertSame('10월 회원 현황 보고', $m['reason']);
        $this->assertSame(['role' => 'guardian'], $m['filters']);
        $this->assertSame('관리자갑', $m['actor_name']);
        $l = $rows->firstWhere('kind', 'list:matching');
        $this->assertSame('매칭 요청', $l['kind_label']);
        $this->assertSame(['status' => 'open'], $l['filters']);
        $b = $rows->firstWhere('result', 'blocked');
        $this->assertSame(['payments', 422], [$b['kind'], $b['status']]);

        $this->assertCount(1, $this->getJson('/api/v1/admin/exports/logs?period=today&result=blocked', $this->h($tok))->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/admin/exports/logs?period=today&kind=members', $this->h($tok))->json('data'));
        [, $br] = $this->createAdminUser('branch');
        $this->getJson('/api/v1/admin/exports/logs', $this->h($br))->assertStatus(403);
    }
}
