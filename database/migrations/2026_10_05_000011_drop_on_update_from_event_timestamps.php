<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 첫 timestamp 칸 자동 갱신 함정 수정(2026-10-05).
 * 이 서버 MariaDB 는 explicit_defaults_for_timestamp=OFF 라서, 표의 「첫 번째」 NOT NULL timestamp 칸에
 * DEFAULT current_timestamp() ON UPDATE current_timestamp() 가 저절로 붙는다. 그래서 그 행을 UPDATE 할 때마다
 * 값이 「지금(DB 시간대=KST 문자열)」으로 바뀌고, 앱은 UTC 로 읽으니 9시간 뒤 시각이 된다.
 * - mnh_client_journals.logged_at: 기관 「확인」 때 기록 시각이 바뀜 → 확인된 행은 작성 시각(created_at)으로 되돌린다
 *   (원래 입력 시각은 남아 있지 않다 — 이 버그가 난 행은 배포 당일 검증 기록뿐).
 * - care_log_shares.expires_at: 공유 링크를 처음 열 때(조회수 UPDATE) 만료가 「첫 열람 + 9시간」으로 줄어듦.
 * (monthly_reports.generated_at·stt_evaluations.evaluated_at 도 같은 꼴이지만 매번 직접 넣거나 UPDATE 가 없어 그대로 둔다)
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE mnh_client_journals MODIFY logged_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'UTC'");
        DB::statement('ALTER TABLE care_log_shares MODIFY expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
        DB::table('mnh_client_journals')->whereNotNull('checked_at')->whereColumn('logged_at', '>', 'created_at')
            ->update(['logged_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        // 되돌리지 않는다(자동 갱신은 버그)
    }
};
