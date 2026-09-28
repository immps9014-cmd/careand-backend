<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 관리자 권한 5단계 — 사업계획서 3.3 「RBAC: 슈퍼관리자·지점장·CS 담당자·데이터 분석가·개발자」(2026-09-28, 구현계획 S2-3).
 * 기존 enum(super·operator·cs·analyst) → super·branch·cs·analyst·developer. operator(운영자)는 지점장(branch)으로 옮긴다.
 * 순서: 새 값 추가 → operator 행 이관 → operator 제거 (MariaDB 10.3 enum 변경은 MODIFY).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE admins MODIFY permission_level ENUM('super','operator','branch','cs','analyst','developer') NOT NULL DEFAULT 'cs'");
        DB::table('admins')->where('permission_level', 'operator')->update(['permission_level' => 'branch']);
        DB::statement("ALTER TABLE admins MODIFY permission_level ENUM('super','branch','cs','analyst','developer') NOT NULL DEFAULT 'cs' COMMENT '권한 5단계'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE admins MODIFY permission_level ENUM('super','operator','branch','cs','analyst','developer') NOT NULL DEFAULT 'operator'");
        DB::table('admins')->whereIn('permission_level', ['branch', 'developer'])->update(['permission_level' => 'operator']);
        DB::statement("ALTER TABLE admins MODIFY permission_level ENUM('super','operator','cs','analyst') NOT NULL DEFAULT 'operator'");
    }
};
