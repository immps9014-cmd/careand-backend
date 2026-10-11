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
        // 기록 엑셀 — 같은 필터, 조건 「k=v」, 관리자 이름·이메일도 마스킹(관리자ID 로 대조), 이 다운로드도 기록에 「다운로드 기록」으로 남는다
        $res = $this->get('/api/v1/admin/exports/list/export-logs?period=today', $this->h($tok, $ok))->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($tmp, $res->getContent());
        $zip = new \ZipArchive();
        $zip->open($tmp);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($tmp);
        $this->assertStringContainsString('관○○', $xml);
        $this->assertStringNotContainsString('관리자갑', $xml);
        $this->assertStringNotContainsString((string) $admin->email, $xml);
        $this->assertMatchesRegularExpression('/<v>' . $admin->id . '<\/v>/', $xml);   // 관리자ID 는 숫자 그대로
        $this->assertStringContainsString('role=guardian', $xml);
        $this->assertStringContainsString('사유 없음', $xml);
        $this->assertStringContainsString('<row r="6">', $xml);   // 머리 3줄 + 기록 3줄
        $this->assertStringNotContainsString('<row r="7">', $xml);
        $again = collect($this->getJson('/api/v1/admin/exports/logs?period=today', $this->h($tok))->json('data'));
        $this->assertSame('다운로드 기록', $again->firstWhere('kind', 'list:export-logs')['kind_label']);

        [, $br] = $this->createAdminUser('branch');
        $this->getJson('/api/v1/admin/exports/logs', $this->h($br))->assertStatus(403);
    }
}
