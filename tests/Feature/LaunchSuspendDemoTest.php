<?php

namespace Tests\Feature;

use App\Models\Caregiver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** 개시 때 데모 계정 정지(launch:suspend-demo) — 실회원은 그대로, 되돌리기는 이전 상태로 */
class LaunchSuspendDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_suspend_and_restore_demo_accounts_only(): void
    {
        Storage::fake('local');
        $demo = User::factory()->caregiver()->create(['email' => 'cg1@demo.careand.kr']);
        $demoCg = Caregiver::factory()->create(['user_id' => $demo->id, 'status' => 'leave']);
        $real = User::factory()->caregiver()->create(['email' => 'real@careand.co.kr']);
        $realCg = Caregiver::factory()->create(['user_id' => $real->id, 'status' => 'active']);

        $this->artisan('launch:suspend-demo --dry-run')->assertSuccessful();
        $this->assertSame('active', $demo->fresh()->status);

        $this->artisan('launch:suspend-demo')->assertSuccessful();
        $this->assertSame('suspended', $demo->fresh()->status);
        $this->assertSame('suspended', $demoCg->fresh()->status);
        $this->assertSame('active', $real->fresh()->status);
        $this->assertSame('active', $realCg->fresh()->status);

        $file = collect(Storage::files('launch'))->first();
        $this->artisan("launch:suspend-demo --restore={$file}")->assertSuccessful();
        $this->assertSame('active', $demo->fresh()->status);
        $this->assertSame('leave', $demoCg->fresh()->status);
    }
}
