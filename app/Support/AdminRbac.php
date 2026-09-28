<?php

namespace App\Support;

/**
 * 관리자 권한 5단계 판정 — 규칙은 config/admin_rbac.php 한 곳(2026-09-28, 구현계획 S2-3).
 */
class AdminRbac
{
    public static function can(?string $level, string $area, bool $write): bool
    {
        if (!$level) {
            return false;
        }
        $rule = config("admin_rbac.areas.{$area}") ?? config('admin_rbac.default');
        return in_array($level, $rule[$write ? 'write' : 'read'] ?? [], true);
    }

    /** 화면용 권한 목록 — {영역: {read: bool, write: bool}} */
    public static function permissionsFor(?string $level): array
    {
        $out = [];
        foreach (config('admin_rbac.areas', []) as $area => $rule) {
            $out[$area] = ['read' => self::can($level, $area, false), 'write' => self::can($level, $area, true)];
        }
        return $out;
    }

    public static function areaOf(string $path): string
    {
        // api/v1/admin/{area}/...
        $parts = explode('/', $path);
        return $parts[3] ?? '';
    }
}
