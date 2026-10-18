<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * 산모신생아 서식 v3(2026-10-18) — 운영사 지시로 제공기관(베이비노트) 서식 문구를 케어앤 브랜드로 그대로 적용한 본문
 * (config/mnh_docs.php)을 새 판으로 올린다. 대상: 이용계약서·개인정보 동의서·이용자 준수사항.
 * 관리자가 화면에서 고친 판(created_by 있음)이 맨 위면 건드리지 않는다. 배포 전 config:cache 필수(마이그레이션이 config 를 읽음).
 */
return new class extends Migration
{
    private const TYPES = ['service_contract', 'privacy_consent', 'user_rules'];
    private const NOTE = '케어앤 브랜드 적용 v3(제공기관 서식 기준, 2026-10-18, 법무 검토 전)';

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
        $used = DB::table('mnh_documents')->pluck('template_id')->filter()->all();
        DB::table('mnh_doc_templates')->where('note', self::NOTE)->whereNotIn('id', $used)->delete();
    }
};
