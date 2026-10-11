<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Scenario;
use Tests\TestCase;

/** 사진 대신 캐릭터(2026-10-11) — 고르면 사진 자리에 그림 복사·avatar_key, 실제 사진 올리면 해제, 지우면 둘 다 비움 */
class CaregiverAvatarTest extends TestCase
{
    use RefreshDatabase, Scenario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpScenario();
        Storage::fake('local');
    }

    public function test_choose_avatar_then_photo_then_delete(): void
    {
        [, $cg, $tok] = $this->createCaregiverUser();
        $h = $this->h($tok);

        $this->postJson('/api/v1/caregivers/me/avatar', ['key' => 'c9'], $h)->assertStatus(422);
        $r = $this->postJson('/api/v1/caregivers/me/avatar', ['key' => 'c3'], $h)->assertOk()->json('data');
        $this->assertSame('c3', $r['avatar_key']);
        $this->assertNotNull($r['photo_url']);
        $this->assertNotContains('photo', $r['missing']);
        Storage::disk('local')->assertExists("caregiver-photos/{$cg->id}.jpg");
        $this->assertSame(file_get_contents(public_path('caregiver-avatars/c3.jpg')), Storage::disk('local')->get("caregiver-photos/{$cg->id}.jpg"));
        // 보호자 쪽에서도 같은 사진 주소로 보인다
        $this->get($r['photo_url'])->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        // 실제 사진을 올리면 캐릭터 해제
        $img = imagecreatetruecolor(40, 40);
        ob_start(); imagejpeg($img); $jpg = ob_get_clean();
        $this->post('/api/v1/caregivers/me/photo', ['photo' => UploadedFile::fake()->createWithContent('me.jpg', $jpg)], $h)->assertOk();
        $this->assertNull(DB::table('caregivers')->where('id', $cg->id)->value('avatar_key'));

        // 다시 캐릭터 → 지우기면 둘 다 비움
        $this->postJson('/api/v1/caregivers/me/avatar', ['key' => 'c1'], $h)->assertOk();
        $this->deleteJson('/api/v1/caregivers/me/photo', [], $h)->assertOk();
        $row = DB::table('caregivers')->where('id', $cg->id)->first(['photo_path', 'avatar_key']);
        $this->assertNull($row->photo_path);
        $this->assertNull($row->avatar_key);
    }

    /** 관리자 — 캐릭터 고르기·지우기, 실제 사진이 있으면 force 없이는 덮지 않음, 회원 상세에 사진·캐릭터, 분석가 차단 */
    public function test_admin_sets_avatar(): void
    {
        [, $tok] = $this->createAdminUser();
        [$cu, $cg, $cTok] = $this->createCaregiverUser();
        $h = $this->h($tok);

        $this->postJson("/api/v1/admin/caregivers/{$cg->id}/avatar", ['key' => 'c5'], $h)->assertOk()->assertJsonPath('data.avatar_key', 'c5');
        $this->getJson("/api/v1/admin/members/{$cu->id}", $h)->assertJsonPath('data.caregiver.avatar_key', 'c5')
            ->assertJsonPath('data.caregiver.photo_url', fn ($v) => is_string($v) && $v !== '');
        $this->postJson("/api/v1/admin/caregivers/{$cg->id}/avatar", ['key' => null], $h)->assertOk()->assertJsonPath('data.avatar_key', null);

        // 본인이 실제 사진을 올렸으면 관리자는 확인(force) 없이 못 덮는다
        $img = imagecreatetruecolor(40, 40);
        ob_start(); imagejpeg($img); $jpg = ob_get_clean();
        $this->post('/api/v1/caregivers/me/photo', ['photo' => UploadedFile::fake()->createWithContent('me.jpg', $jpg)], $this->h($cTok))->assertOk();
        $h = $this->h($tok);
        $this->postJson("/api/v1/admin/caregivers/{$cg->id}/avatar", ['key' => 'c1'], $h)->assertStatus(409)->assertJson(['error_code' => 'HAS_PHOTO']);
        // 지우기(null)는 실제 사진을 건드리지 않는다
        $this->postJson("/api/v1/admin/caregivers/{$cg->id}/avatar", ['key' => null], $h)->assertOk();
        $this->assertNotNull(DB::table('caregivers')->where('id', $cg->id)->value('photo_path'));
        $this->postJson("/api/v1/admin/caregivers/{$cg->id}/avatar", ['key' => 'c1', 'force' => true], $h)->assertOk()->assertJsonPath('data.avatar_key', 'c1');

        [, $an] = $this->createAdminUser('analyst');
        $this->postJson("/api/v1/admin/caregivers/{$cg->id}/avatar", ['key' => 'c2'], $this->h($an))->assertStatus(403);
    }

    /** 보호자 추천·찜 카드에도 사진(캐릭터) 주소 */
    public function test_recommended_and_favorites_carry_photo_url(): void
    {
        [, $cg] = $this->createCaregiverUser();
        DB::table('caregivers')->where('id', $cg->id)->update(['service_domains' => 'senior']);
        \App\Support\CaregiverProfileExtras::setAvatar((int) $cg->id, 'c2');
        [$gu, , $gTok] = $this->createGuardianUser();
        DB::table('caregiver_favorites')->insert(['user_id' => $gu->id, 'caregiver_id' => $cg->id, 'created_at' => now(), 'updated_at' => now()]);
        $fav = collect($this->getJson('/api/v1/caregivers/favorites', $this->h($gTok))->assertOk()->json('data'))->firstWhere('id', $cg->id);
        $this->assertNotEmpty($fav['photo_url']);
        $rec = collect($this->getJson('/api/v1/caregivers/recommended', $this->h($gTok))->assertOk()->json('data'))->firstWhere('id', $cg->id);
        if ($rec) {
            $this->assertNotEmpty($rec['photo_url']);
        }
    }
}
