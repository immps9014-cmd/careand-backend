<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 영역별 안내 콘텐츠·FAQ·지역 공지(CAREN-REF-01 3단계, 2026-10-10) — 관리자가 고치고 웹·앱이 같은 API 로 읽는다.
 *   kind      guide(안내 문단) | faq(질문·답) | notice(공지)
 *   placement 어디에 나오는지: service_scope(신청 화면 서비스 범위) · support(고객센터 FAQ) · guide(이용 가이드 FAQ)
 *             · voucher_guide(바우처 안내) · home(홈 공지) …
 *   domain    null = 공통, 아니면 config/service_domains.php 키
 *   audience  all | guardian | caregiver,  platform all | web | app (화면 위치 안내가 웹·앱에서 다를 때)
 *   regions   null = 전국, 아니면 ["경기","서울"] 처럼 시·도 짧은 이름
 *   blocks    [{type:p,text} | {type:list,items,role?} | {type:note,text,tone?} | {type:table,head,rows} | {type:link,label,href}]
 *   status    draft | published,  starts_on/ends_on 은 KST 날짜(포함)
 * 시드: 코드에 박혀 있던 서비스 범위·FAQ 를 문구 그대로 옮긴다(database/data/contents_seed_20261010.json).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('domain_contents', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->string('placement', 40);
            $table->string('domain', 30)->nullable();
            $table->string('audience', 20)->default('all');
            $table->string('platform', 10)->default('all');
            $table->json('regions')->nullable();
            $table->string('title', 200);
            $table->json('blocks');
            $table->string('tone', 10)->default('info')->comment('notice 강조: info|warn');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->string('status', 20)->default('draft');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('source_url', 500)->nullable();
            $table->string('source_note', 500)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable()->comment('검수자(관리자) — 법무·운영 검수 표시');
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['placement', 'status']);
            $table->index(['kind', 'domain']);
        });

        $now = now();
        $rows = json_decode(file_get_contents(database_path('data/contents_seed_20261010.json')), true);
        $rows[] = ['kind' => 'guide', 'placement' => 'voucher_guide', 'domain' => 'postpartum', 'audience' => 'all', 'platform' => 'all',
            'title' => '신청 전에 알아 두세요', 'sort' => 10, 'source_note' => '이관: member-web components/mnh/voucher-guide.tsx(2026-10-10)',
            'blocks' => [['type' => 'list', 'items' => [
                '바우처 신청: 출산 예정일 40일 전부터 출산 후 60일까지, 주소지 보건소·복지로·정부24에서 해요.',
                '바우처는 출산일로부터 90일 안에 다 써야 해요(남아도 소멸).',
                '보건소에서 받은 결정 통지의 유형(예: A-통합-①형)으로 케어앤에 계약을 신청하면 돼요.',
                '본인부담금은 서비스 시작 전에 케어앤에 먼저 내고, 정부지원금은 국민행복카드 바우처로 결제돼요.',
            ]]]];
        // 지역 공지 예시 — 확인 전이라 초안으로만 둔다(운영자가 보건소 공고 확인 후 게시)
        $rows[] = ['kind' => 'notice', 'placement' => 'home', 'domain' => 'postpartum', 'audience' => 'guardian', 'platform' => 'all',
            'regions' => ['경기'], 'tone' => 'warn', 'starts_on' => '2026-10-01',
            'title' => '경기도 산모신생아 바우처 지원 기준 변경',
            'blocks' => [['type' => 'p', 'text' => '2026년 10월 1일 이후 신청하는 경기도 출산가정은 기준중위소득 150% 초과(라형) 지원이 중단돼요. 일부 시·군은 연말까지 유지될 수 있으니 주소지 보건소에 확인해 주세요.']],
            'sort' => 0, 'source_url' => 'https://www.hanam.go.kr/health/contents.do?key=956',
            'source_note' => '하남시 보건소 안내 + 산모피아 앱 공지(김포·성남 12-31까지 유지 — 미확인). 게시 전 경기도·시군 공고 대조 필요'];

        DB::table('domain_contents')->insert(array_map(fn ($r) => [
            'kind' => $r['kind'], 'placement' => $r['placement'], 'domain' => $r['domain'] ?? null,
            'audience' => $r['audience'] ?? 'all', 'platform' => $r['platform'] ?? 'all',
            'regions' => isset($r['regions']) ? json_encode($r['regions'], JSON_UNESCAPED_UNICODE) : null,
            'title' => $r['title'], 'blocks' => json_encode($r['blocks'], JSON_UNESCAPED_UNICODE),
            'tone' => $r['tone'] ?? 'info', 'sort' => $r['sort'] ?? 0,
            // 이관한 문구는 지금 화면에 이미 나가는 것이라 게시 상태, 새 공지만 초안
            'status' => $r['kind'] === 'notice' ? 'draft' : 'published',
            'starts_on' => $r['starts_on'] ?? null, 'source_url' => $r['source_url'] ?? null, 'source_note' => $r['source_note'] ?? null,
            'created_at' => $now, 'updated_at' => $now,
        ], $rows));
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_contents');
    }
};
