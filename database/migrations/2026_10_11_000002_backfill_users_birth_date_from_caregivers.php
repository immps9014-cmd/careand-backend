<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 기존 돌봄전문가의 생년월일(caregivers.birth_date, 자격 정보)을 회원정보(users.birth_date)로 옮긴다(2026-10-11).
 * 회원정보가 비어 있는 활동 회원만 — 이미 값이 있으면 건드리지 않고, 탈퇴(소프트삭제)한 회원·돌봄전문가는 제외한다.
 * updated_at 은 바꾸지 않는다(데이터 이전이지 회원 수정이 아님). 적용 시점 대상 32명.
 */
return new class extends Migration
{
    public function up(): void
    {
        $n = DB::update(
            'UPDATE users u JOIN caregivers c ON c.user_id = u.id AND c.deleted_at IS NULL
             SET u.birth_date = c.birth_date
             WHERE u.birth_date IS NULL AND c.birth_date IS NOT NULL AND u.deleted_at IS NULL'
        );
        logger()->info("backfill users.birth_date from caregivers: {$n} rows");
    }

    /** 되돌리기 — 돌봄전문가 생년월일과 같은 값만 비운다(이전 직후엔 정확히 원래대로. 나중에 같은 값을 직접 넣은 회원도 비워질 수 있음) */
    public function down(): void
    {
        DB::update(
            'UPDATE users u JOIN caregivers c ON c.user_id = u.id
             SET u.birth_date = NULL
             WHERE u.birth_date = c.birth_date'
        );
    }
};
