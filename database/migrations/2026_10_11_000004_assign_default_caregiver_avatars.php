<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * 사진 없는 기존 돌봄전문가에게 캐릭터를 임의 배정(2026-10-11 사용자 요청).
 * 대상: 활동(소프트삭제 아님)·사진 없음. 실제 사진이 있는 사람은 건드리지 않는다.
 * 고르는 법(재현 가능한 임의): 성별 묶음 안에서 돌봄전문가 id 로 순서대로 섞는다 —
 *   남: c2 숏컷·c7 가르마·c8 안경 / 여: c1 단발·c3 묶은 머리·c4 곱슬·c6 포니테일, 55세 이상 여성은 c5 은발도 섞음.
 * 파일은 사진 자리(storage/app/caregiver-photos/{id}.jpg)에 복사하고, 배포가 root 로 돌아도 웹(apache)이 덮어쓸 수 있게 폴더 소유자로 맞춘다.
 * 되돌리기(down): 이 마이그레이션이 배정한 캐릭터(avatar_key 있음 + 그 뒤 바뀌지 않음)만 지운다 — 대상 id 는 caregivers.avatar_key·photo_updated_at 로 판별.
 */
return new class extends Migration
{
    private const MARK = '2026-10-11 00:00:04';   // 이 배정의 photo_updated_at 표식(UTC) — down 이 이것만 지운다

    public function up(): void
    {
        $male = ['c2', 'c7', 'c8'];
        $female = ['c1', 'c3', 'c4', 'c6'];
        $femaleSenior = ['c5', 'c1', 'c3', 'c5', 'c6'];
        $seniorCut = now('Asia/Seoul')->subYears(55)->toDateString();
        $dir = storage_path('app/caregiver-photos');
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $owner = fileowner($dir);
        $group = filegroup($dir);

        $rows = DB::table('caregivers')->whereNull('deleted_at')->whereNull('photo_path')->orderBy('id')->get(['id', 'gender', 'birth_date']);
        $n = 0;
        foreach ($rows as $c) {
            $pool = $c->gender === 'M' ? $male : ($c->birth_date && $c->birth_date <= $seniorCut ? $femaleSenior : $female);
            $key = $pool[$c->id % count($pool)];
            $src = public_path("caregiver-avatars/{$key}.jpg");
            if (!is_file($src)) {
                continue;
            }
            $path = "caregiver-photos/{$c->id}.jpg";
            Storage::disk('local')->put($path, (string) file_get_contents($src));
            @chown(storage_path("app/{$path}"), $owner);
            @chgrp(storage_path("app/{$path}"), $group);
            DB::table('caregivers')->where('id', $c->id)->update(['photo_path' => $path, 'photo_updated_at' => self::MARK, 'avatar_key' => $key]);
            $n++;
        }
        logger()->info("assign default caregiver avatars: {$n}");
    }

    public function down(): void
    {
        $ids = DB::table('caregivers')->whereNotNull('avatar_key')->where('photo_updated_at', self::MARK)->pluck('id');
        foreach ($ids as $id) {
            Storage::disk('local')->delete("caregiver-photos/{$id}.jpg");
        }
        DB::table('caregivers')->whereIn('id', $ids)->update(['photo_path' => null, 'photo_updated_at' => null, 'avatar_key' => null]);
    }
};
