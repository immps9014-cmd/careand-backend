<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 출산 전 예비예약 → 출산 후 확정(CAREN-REF-01 2단계, 2026-10-10).
 * - postpartum_clients.birth_confirmed: 0 = delivery_date 가 출산 예정일, 1 = 실제 출산일.
 *   expected_delivery_date 는 처음 받은 예정일(실제와 2주 이상 차이 판정용), birth_confirmed_at 은 확정 시각.
 *   기존 행: 출산(예정)일이 오늘(KST) 이후면 예정, 아니면 출산으로 본다.
 * - mnh_contracts.provisional: 출산 전에 신청한 예비 계약. 출산일을 등록하면 같은 간격만큼 개시일을 옮겨 확정하고,
 *   출산일·개시일이 2주 이상 바뀌거나 담당 일정이 겹치면 start_change_request 로 남겨 기관이 확정한다.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('postpartum_clients', function (Blueprint $table) {
            $table->boolean('birth_confirmed')->default(true)->comment('0=delivery_date 가 예정일');
            $table->date('expected_delivery_date')->nullable()->comment('처음 받은 출산 예정일');
            $table->timestamp('birth_confirmed_at')->nullable();
        });
        Schema::table('mnh_contracts', function (Blueprint $table) {
            $table->boolean('provisional')->default(false)->comment('출산 전 예비 계약');
            $table->json('start_change_request')->nullable()->comment('기관 확인 대기 {start_date, reason, birth_gap, start_gap, requested_at}');
        });

        $today = now('Asia/Seoul')->toDateString();
        DB::table('postpartum_clients')->where('delivery_date', '>', $today)
            ->update(['birth_confirmed' => false, 'expected_delivery_date' => DB::raw('delivery_date')]);
        DB::table('mnh_contracts as c')->join('postpartum_clients as p', 'p.id', '=', 'c.postpartum_client_id')
            ->where('p.birth_confirmed', false)->whereIn('c.status', ['applied', 'confirmed', 'active'])
            ->update(['c.provisional' => true]);
    }

    public function down(): void
    {
        Schema::table('mnh_contracts', function (Blueprint $table) {
            $table->dropColumn(['provisional', 'start_change_request']);
        });
        Schema::table('postpartum_clients', function (Blueprint $table) {
            $table->dropColumn(['birth_confirmed', 'expected_delivery_date', 'birth_confirmed_at']);
        });
    }
};
