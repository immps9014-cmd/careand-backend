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
}
