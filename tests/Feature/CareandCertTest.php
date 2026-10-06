<?php

namespace Tests\Feature;

use App\Models\Caregiver;
use App\Models\Guardian;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Scenario;
use Tests\TestCase;

/** 케어앤에듀 인증 돌봄전문가(2026-10-07) — 실제 기록 기준 자동 부여·마크 노출·관리자 취소/직접 부여 */
class CareandCertTest extends TestCase
{
    use RefreshDatabase, Scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
        config(['careand_cert.min_sessions' => 5, 'careand_cert.min_reviews' => 3, 'careand_cert.min_rating' => 4.5, 'careand_cert.auto_grant' => true]);
        \App\Services\CareandCertService::forgetMemo();
    }

    /** 완료 돌봄 $n 회 + 앞쪽 매칭들에 보호자 후기 */
    private function record(Caregiver $cg, Guardian $g, int $n, array $ratings): void
    {
        for ($i = 0; $i < $n; $i++) {
            [$m] = $this->makeMatch($this->makeRequest($g), $cg, 'completed', now()->subDays($i + 1));
            if (isset($ratings[$i])) {
                DB::table('reviews')->insert(['match_id' => $m->id, 'reviewer_id' => $g->user_id, 'reviewer_role' => 'guardian',
                    'rating' => $ratings[$i], 'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function test_auto_grant_shows_mark_everywhere(): void
    {
        [$gu, $g, $gTok] = $this->createGuardianUser();
        [$cu, $cg, $cTok] = $this->createCaregiverUser();
        [, $other] = $this->createCaregiverUser();
        $this->record($cg, $g, 5, [5, 5, 4]);      // 평균 4.67
        $this->record($other, $g, 4, [5, 5, 5]);   // 활동 4회 — 미달

        $this->artisan('caregivers:certify')->assertSuccessful();

        $cert = DB::table('certifications')->where('program', 'careand_certified')->get();
        $this->assertCount(1, $cert);
        $this->assertSame($cu->id, (int) $cert[0]->user_id);
        $this->assertMatchesRegularExpression('/^CAE-\d{4}-0001$/', $cert[0]->cert_number);
        $this->assertSame(1, $this->notifications('CERT_GRANTED')->count());

        $this->getJson("/api/v1/caregivers/{$cg->id}", $this->h($gTok))->assertOk()
            ->assertJsonPath('data.careand_certified', true)
            ->assertJsonPath('data.careand_cert.number', $cert[0]->cert_number);
        $this->getJson("/api/v1/caregivers/{$other->id}", $this->h($gTok))->assertOk()
            ->assertJsonPath('data.careand_certified', false);
        $this->getJson('/api/v1/caregivers/me/certificate', $this->h($cTok))->assertOk()
            ->assertJsonPath('data.certified', true)->assertJsonPath('data.stats.sessions', 5);

        // 두 번 돌려도 한 번만
        $this->artisan('caregivers:certify')->assertSuccessful();
        $this->assertSame(1, DB::table('certifications')->count());
    }

    public function test_low_rating_or_few_reviews_not_granted(): void
    {
        [, $g] = $this->createGuardianUser();
        [, $low] = $this->createCaregiverUser();
        [, $few] = $this->createCaregiverUser();
        $this->record($low, $g, 6, [4, 4, 5]);   // 4.33
        $this->record($few, $g, 6, [5, 5]);      // 후기 2건
        $this->artisan('caregivers:certify')->assertSuccessful();
        $this->assertSame(0, DB::table('certifications')->count());
    }

    public function test_admin_revoke_blocks_auto_regrant_and_manual_grant(): void
    {
        [, $g] = $this->createGuardianUser();
        [, $cg] = $this->createCaregiverUser();
        [, $aTok] = $this->createAdminUser();
        $this->record($cg, $g, 5, [5, 5, 5]);
        $this->artisan('caregivers:certify');

        $this->getJson('/api/v1/admin/caregivers/certifications', $this->h($aTok))->assertOk()
            ->assertJsonCount(1, 'data.certified');
        $this->deleteJson("/api/v1/admin/caregivers/{$cg->id}/certification", [], $this->h($aTok))->assertStatus(422);   // 사유 필수
        $this->deleteJson("/api/v1/admin/caregivers/{$cg->id}/certification", ['reason' => '민원 확인'], $this->h($aTok))->assertOk();
        \App\Services\CareandCertService::forgetMemo();
        $this->assertFalse(app(\App\Services\CareandCertService::class)->isCertifiedCaregiver($cg->id));

        $this->artisan('caregivers:certify');
        $this->assertSame(0, DB::table('certifications')->whereNull('revoked_at')->count());   // 자동 재부여 없음

        $this->postJson("/api/v1/admin/caregivers/{$cg->id}/certification", ['note' => '재심 통과'], $this->h($aTok))->assertOk();
        $this->assertMatchesRegularExpression('/-0002$/', DB::table('certifications')->whereNull('revoked_at')->value('cert_number'));
    }
}
