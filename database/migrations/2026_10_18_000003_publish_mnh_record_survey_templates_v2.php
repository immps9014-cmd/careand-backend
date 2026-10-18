<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * 산모신생아 서식 v2(CAREN-MNH-02 B단계, 2026-10-18) — 제공기록지 ①~⑫·만족도 B형으로 바꾼 본문(config/mnh_docs.php)을
 * 새 판으로 올린다. 대상: 서비스 제공기록지·만족도 모니터링(칸 목록은 config 에서 바로 바뀐다).
 * 관리자가 화면에서 고친 판(created_by 있음)이 맨 위면 건드리지 않는다 — 운영자가 정한 문안이 우선.
 * DB 에 v1 이 아직 없으면(처음 쓰기 전) 아무것도 안 한다 — 처음 쓸 때 config 의 새 본문이 v1 로 들어간다.
 */
return new class extends Migration
{
    private const TYPES = ['provision_record', 'satisfaction'];
    private const NOTE = '실서식 반영 v2(2026-10-18 B, 운영사 검토 전)';

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
