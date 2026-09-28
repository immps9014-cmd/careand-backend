<?php

use App\Support\MedicalCrypto;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 의료정보(자유 입력) 암호화 — 사업계획서 3.3 (2026-09-28, 구현계획 S2-4).
 * 대상 9개 컬럼을 AES-256-GCM(전용 키 MEDICAL_DATA_KEY)으로 바꾼다. 모델은 MedicalText/MedicalJson 캐스트.
 * 짧은 varchar 3개는 암호문 길이 때문에 TEXT 로 넓힌다. 이미 암호문인 값은 건너뛴다(재실행 안전).
 * 제외: 질환 목록(diseases, 매칭·가격·온톨로지 입력 — 별도 결정), 등급·거동 등 선택값, EPDS·바이탈 수치(판정 로직 입력).
 */
return new class extends Migration
{
    private const COLS = [
        'seniors' => ['special_notes', 'care_grade_no'],
        'nursing_patients' => ['special_notes'],
        'postpartum_clients' => ['pregnancy_complications', 'postpartum_conditions', 'medications', 'special_notes'],
        'newborns' => ['special_conditions'],
        'children' => ['special_notes'],
        'mental_care_clients' => ['special_notes'],
    ];

    public function up(): void
    {
        DB::statement("ALTER TABLE seniors MODIFY care_grade_no TEXT NULL COMMENT '장기요양인정번호(암호화)'");
        DB::statement("ALTER TABLE children MODIFY special_notes TEXT NULL COMMENT '특이사항(암호화)'");
        DB::statement("ALTER TABLE mental_care_clients MODIFY special_notes TEXT NULL COMMENT '특이사항(암호화)'");

        foreach (self::COLS as $table => $cols) {
            foreach ($cols as $col) {
                DB::table($table)->whereNotNull($col)->where($col, '<>', '')->orderBy('id')
                    ->select('id', $col)->chunkById(200, function ($rows) use ($table, $col) {
                        foreach ($rows as $r) {
                            if (!MedicalCrypto::isEncrypted($r->$col)) {
                                DB::table($table)->where('id', $r->id)->update([$col => MedicalCrypto::encrypt($r->$col)]);
                            }
                        }
                    });
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLS as $table => $cols) {
            foreach ($cols as $col) {
                DB::table($table)->whereNotNull($col)->orderBy('id')->select('id', $col)
                    ->chunkById(200, function ($rows) use ($table, $col) {
                        foreach ($rows as $r) {
                            if (MedicalCrypto::isEncrypted($r->$col)) {
                                DB::table($table)->where('id', $r->id)->update([$col => MedicalCrypto::decrypt($r->$col)]);
                            }
                        }
                    });
            }
        }
        DB::statement("ALTER TABLE seniors MODIFY care_grade_no VARCHAR(30) NULL COMMENT '장기요양인정번호'");
        DB::statement('ALTER TABLE children MODIFY special_notes VARCHAR(500) NULL');
        DB::statement('ALTER TABLE mental_care_clients MODIFY special_notes VARCHAR(500) NULL');
    }
};
