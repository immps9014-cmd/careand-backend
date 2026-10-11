<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 55세 이상 여성 돌봄전문가는 은발(c5) 캐릭터로 다시 배정(2026-10-11 사용자 요청).
 * 대상: 000004 자동 배정분(photo_updated_at 표식 2026-10-11 00:00:04)만 — 본인·관리자가 직접 고른 캐릭터나 실제 사진은 건드리지 않는다.
 * 새 표식(00:00:05)으로 바꿔 사진 주소의 v 가 달라져 브라우저 캐시에 남은 예전 그림이 바뀐다.
 * 적용 시점 대상: id 12(c3)·6(c1)·7(c3)·16(c1)·19(c6) — down 이 이 원래 캐릭터로 되돌린다.
 */
return new class extends Migration
{
    private const PREV_MARK = '2026-10-11 00:00:04';
    private const MARK = '2026-10-11 00:00:05';
    private const BEFORE = [12 => 'c3', 6 => 'c1', 7 => 'c3', 16 => 'c1', 19 => 'c6'];

    public function up(): void
    {
        $cut = now('Asia/Seoul')->subYears(55)->toDateString();
        $rows = DB::table('caregivers')->whereNull('deleted_at')->where('gender', 'F')->where('birth_date', '<=', $cut)
            ->where('photo_updated_at', self::PREV_MARK)->whereNotNull('avatar_key')->where('avatar_key', '!=', 'c5')
            ->get(['id']);
        foreach ($rows as $c) {
            $this->put((int) $c->id, 'c5', self::MARK);
        }
        logger()->info('reassign senior female avatars to c5: ' . $rows->count());
    }

    public function down(): void
    {
        foreach (self::BEFORE as $id => $key) {
            if (DB::table('caregivers')->where('id', $id)->where('photo_updated_at', self::MARK)->where('avatar_key', 'c5')->exists()) {
                $this->put($id, $key, self::PREV_MARK);
            }
        }
    }

    private function put(int $id, string $key, string $mark): void
    {
        $src = public_path("caregiver-avatars/{$key}.jpg");
        if (!is_file($src)) {
            return;
        }
        $path = "caregiver-photos/{$id}.jpg";
        Storage::disk('local')->put($path, (string) file_get_contents($src));
        $dir = storage_path('app/caregiver-photos');
        @chown(storage_path("app/{$path}"), fileowner($dir));
        @chgrp(storage_path("app/{$path}"), filegroup($dir));
        DB::table('caregivers')->where('id', $id)->update(['photo_path' => $path, 'photo_updated_at' => $mark, 'avatar_key' => $key]);
    }
};
