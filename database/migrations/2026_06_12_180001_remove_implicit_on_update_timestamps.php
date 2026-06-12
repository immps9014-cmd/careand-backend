<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * MariaDB 레거시 동작 수정: 첫 TIMESTAMP 컬럼이 암묵적으로
     * `ON UPDATE CURRENT_TIMESTAMP`를 가져, 행 갱신 시 업무 시각
     * (예약일시/탐지시각/측정시각 등)이 현재시각으로 오염되던 버그.
     * 명시적 DEFAULT만 남기고 ON UPDATE 절을 제거한다. (E2E로 발견, 2026-06-12)
     */
    private const COLUMNS = [
        ["ai_inference_logs", "inferred_at"],
        ["ai_log_summaries", "generated_at"],
        ["ai_recommendations", "generated_at"],
        ["anomaly_alerts", "detected_at"],
        ["attendance_logs", "logged_at"],
        ["audit_logs", "logged_at"],
        ["care_activities", "performed_at"],
        ["care_photos", "taken_at"],
        ["chatbot_sessions", "started_at"],
        ["health_timeseries", "recorded_at"],
        ["matches", "scheduled_start"],
        ["match_requests", "scheduled_start"],
        ["vital_records", "measured_at"],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as [$table, $column]) {
            DB::statement("ALTER TABLE {$table}
                MODIFY {$column} TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
        }
    }

    public function down(): void
    {
        // 의도된 버그 제거 — 원복 불필요 (원복 시 오염 동작이 되살아남)
    }
};
