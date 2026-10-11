<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 남성 돌봄전문가 캐릭터를 서로 다르게(2026-10-11 사용자 결정 「둘 다 다른 캐릭터로」).
 * - 32 최간병: 자동 배정 c8(안경) → c2(숏컷). 자동 배정분(표식 00:00:04)일 때만.
 * - 36 김기버: 본인이 올린 시험용 사진(파란 그라데이션) → c7(가르마). 원래 사진은 caregiver-photos-backup/36-20261011.jpg 로 보관.
 * 새 표식 00:00:06(사진 주소 v 가 바뀌어 캐시 갱신). down 은 둘 다 원래대로(36 은 백업 사진·원래 시각 복원).
 */
return new class extends Migration
{
    private const MARK = '2026-10-11 00:00:06';
    private const BACKUP = 'caregiver-photos-backup/36-20261011.jpg';

    public function up(): void
    {
        if (DB::table('caregivers')->where('id', 32)->where('avatar_key', 'c8')->where('photo_updated_at', '2026-10-11 00:00:04')->exists()) {
            $this->put(32, 'c2');
        }
        $k = DB::table('caregivers')->where('id', 36)->whereNull('deleted_at')->first(['photo_path', 'avatar_key', 'photo_updated_at']);
        if ($k && $k->photo_path && !$k->avatar_key) {
            if (Storage::disk('local')->exists($k->photo_path) && !Storage::disk('local')->exists(self::BACKUP)) {
                Storage::disk('local')->put(self::BACKUP, Storage::disk('local')->get($k->photo_path));
            }
            $this->put(36, 'c7');
        }
    }

    public function down(): void
    {
        if (DB::table('caregivers')->where('id', 32)->where('avatar_key', 'c2')->where('photo_updated_at', self::MARK)->exists()) {
            $this->put(32, 'c8', '2026-10-11 00:00:04');
        }
        if (DB::table('caregivers')->where('id', 36)->where('avatar_key', 'c7')->where('photo_updated_at', self::MARK)->exists()
            && Storage::disk('local')->exists(self::BACKUP)) {
            Storage::disk('local')->put('caregiver-photos/36.jpg', Storage::disk('local')->get(self::BACKUP));
            DB::table('caregivers')->where('id', 36)->update(['photo_path' => 'caregiver-photos/36.jpg', 'photo_updated_at' => '2026-10-05 12:00:28', 'avatar_key' => null]);
        }
    }

    private function put(int $id, string $key, string $mark = self::MARK): void
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
