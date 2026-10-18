<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * 산모신생아 서식 v2(CAREN-MNH-02 A단계, 2026-10-18) — 실제 제공기관 서식을 보고 보강한 본문(config/mnh_docs.php)을
 * 새 판으로 올린다. 대상: 이용계약서·개인정보 동의서·준수사항·근로계약서.
 * 관리자가 화면에서 고친 판(created_by 있음)이 맨 위면 건드리지 않는다 — 운영자가 정한 문안이 우선.
 * DB 에 v1 이 아직 없으면(처음 쓰기 전) 아무것도 안 한다 — 처음 쓸 때 config 의 새 본문이 v1 로 들어간다.
 */
return new class extends Migration
{
    private const TYPES = ['service_contract', 'privacy_consent', 'user_rules', 'employment_contract'];
    private const NOTE = '실서식 반영 v2(2026-10-18, 운영사 검토 전)';

    public function up(): void
    {
        foreach (self::TYPES as $type) {
            $cur = DB::table('mnh_doc_templates')->where('doc_type', $type)->orderByDesc('version')->first();
            $cfg = config("mnh_docs.types.$type");
            if (!$cur || $cur->created_by !== null || $cur->body === $cfg['body']) {
                continue;
            }
            DB::table('mnh_doc_templates')->insert([
                'doc_type' => $type, 'version' => $cur->version + 1, 'title' => $cfg['label'], 'body' => $cfg['body'],
                'note' => self::NOTE, 'created_by' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // 이 판으로 발행된 서류가 있으면 남긴다(서류가 판을 가리킴)
        $used = DB::table('mnh_documents')->pluck('template_id')->filter()->all();
        DB::table('mnh_doc_templates')->where('note', self::NOTE)->whereNotIn('id', $used)->delete();
    }
};
