<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Scenario;
use Tests\TestCase;

/** 목록 엑셀 공용(2026-10-11) — 7개 목록 전부 내려받기·필터 반영·마스킹·쪽 넘김·권한·감사로그 */
class AdminListExportTest extends TestCase
{
    use RefreshDatabase, Scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
    }

    private function sheet(string $url, string $tok): string
    {
        $res = $this->get($url, $this->h($tok, ['X-Access-Reason' => rawurlencode('목록 점검 다운로드')]))->assertOk();
        $tmp = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($tmp, $res->getContent());
        $zip = new \ZipArchive();
        $zip->open($tmp);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($tmp);

        return $xml;
    }

    public function test_all_lists_export_with_masking_and_filters(): void
    {
        [$admin, $tok] = $this->createAdminUser();
        [$gu, $g] = $this->createGuardianUser();
        $gu->update(['name' => '박보호']);
        [$cu, $cg] = $this->createCaregiverUser();
        $cu->update(['name' => '최전문']);
        // 매칭 요청 105건(쪽 넘김 확인) + 확정 매칭 1건
        for ($i = 0; $i < 105; $i++) {
            $this->makeRequest($g);
        }
        $this->makeMatch($this->makeRequest($g), $cg);

        foreach (['matching', 'contracts', 'care-sessions', 'monitoring', 'caregivers', 'care-logs', 'mnh-contracts'] as $list) {
            $xml = $this->sheet("/api/v1/admin/exports/list/{$list}", $tok);
            $this->assertStringContainsString('개인정보 마스킹 파일', $xml, $list);
            $this->assertStringNotContainsString('박보호', $xml, $list);
            $this->assertStringNotContainsString('최전문', $xml, $list);
        }
        // 쪽 넘김: 매칭 요청 106건 전부 + 머리글 3줄 → 마지막 행 109
        $all = $this->sheet('/api/v1/admin/exports/list/matching', $tok);
        $this->assertStringContainsString('<row r="109">', $all);
        $this->assertStringContainsString('박○○', $all);
        // 필터 반영: status=matched 는 1건
        $matched = $this->sheet('/api/v1/admin/exports/list/matching?status=matched', $tok);
        $this->assertStringContainsString('<row r="4">', $matched);
        $this->assertStringNotContainsString('<row r="5">', $matched);
        $this->assertStringContainsString('매칭됨', $matched);
        $this->assertStringContainsString('status=matched', $matched);
        // 돌봄전문가: 연락처 마스킹
        $cgx = $this->sheet('/api/v1/admin/exports/list/caregivers', $tok);
        $this->assertStringNotContainsString((string) $cu->phone, $cgx);

        // 거부: 없는 목록·사유 없음·지점장
        $this->getJson('/api/v1/admin/exports/list/nope', $this->h($tok, ['X-Access-Reason' => rawurlencode('목록 점검 다운로드')]))->assertStatus(404);
        $this->getJson('/api/v1/admin/exports/list/matching', $this->h($tok))->assertStatus(422);
        [, $br] = $this->createAdminUser('branch');
        $this->getJson('/api/v1/admin/exports/list/matching', $this->h($br, ['X-Access-Reason' => rawurlencode('목록 점검 다운로드')]))->assertStatus(403);
        $this->assertSame(10, DB::table('audit_logs')->where('action', 'export.list.xlsx')->where('actor_id', $admin->id)->count());
    }
}
