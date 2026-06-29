<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 3 — 아이돌봄(childcare) 오픈.
     *  1) children 대상 테이블(보호자 소유, seniors 패턴 + home_lat/lng → generic 거리매칭 재사용).
     *  2) enum/SET 4곳에 'childcare' append (ALGORITHM=INSTANT).
     *  3) match_requests.childcare_child_id FK.
     *  4) 카테고리(CC_*) + pricing_rules 시드(요율 잠정).
     *
     * 설계서: /root/CAREAND-DOMAIN-INTEGRATION.md (Phase 3)
     */
    private const ROWS = [
        ['code' => 'CC_PICKUP', 'name' => '등하원 동행', 'base_rate' => 14000, 'description' => '어린이집·유치원·학원 등하원 동행'],
        ['code' => 'CC_PLAY',   'name' => '놀이돌봄',     'base_rate' => 14000, 'description' => '가정 방문 놀이·학습 돌봄'],
        ['code' => 'CC_INFANT', 'name' => '영아돌봄',     'base_rate' => 16000, 'description' => '36개월 이하 영아 전담 돌봄'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('children')) {
            Schema::create('children', function (Blueprint $table) {
                $table->id();
                $table->foreignId('guardian_id')->constrained('guardians');
                $table->string('name', 50);
                $table->date('birth_date');
                $table->enum('gender', ['M', 'F']);
                $table->string('home_address', 255);
                $table->decimal('home_lat', 10, 7)->nullable();
                $table->decimal('home_lng', 10, 7)->nullable();
                $table->string('special_notes', 500)->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index('home_lat');
            });
        }

        // enum/SET append
        DB::statement("ALTER TABLE match_requests
            MODIFY service_domain ENUM('senior','postpartum','nursing','housekeeping','living_support','childcare')
            NOT NULL DEFAULT 'senior' COMMENT '서비스 도메인', ALGORITHM=INSTANT");
        DB::statement("ALTER TABLE caregivers
            MODIFY service_domains SET('senior','postpartum','nursing','housekeeping','living_support','childcare')
            NOT NULL DEFAULT 'senior' COMMENT '활동 도메인 SET', ALGORITHM=INSTANT");
        DB::statement("ALTER TABLE mock_interviews
            MODIFY target_domain ENUM('senior','postpartum','nursing','housekeeping','living_support','childcare')
            NOT NULL, ALGORITHM=INSTANT");
        DB::statement("ALTER TABLE self_introduction_interviews
            MODIFY extracted_target_domain ENUM('senior','postpartum','both','nursing','housekeeping','living_support','childcare')
            NULL DEFAULT NULL, ALGORITHM=INSTANT");

        // match_requests FK
        if (!Schema::hasColumn('match_requests', 'childcare_child_id')) {
            Schema::table('match_requests', function (Blueprint $table) {
                $table->foreignId('childcare_child_id')->nullable()->after('postpartum_client_id')
                    ->comment('아이돌봄 아동 ID (childcare 도메인 시)')
                    ->constrained('children');
            });
        }

        // 카테고리 + 가격규칙(COMPANION 템플릿 복제)
        $now = now();
        $template = DB::table('service_categories')->where('code', 'COMPANION')->value('id');
        foreach (self::ROWS as $r) {
            $id = DB::table('service_categories')->where('code', $r['code'])->value('id');
            if (!$id) {
                $id = DB::table('service_categories')->insertGetId([
                    'code' => $r['code'], 'domain' => 'childcare', 'name' => $r['name'],
                    'description' => $r['description'], 'base_rate' => $r['base_rate'],
                    'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            if ($template && DB::table('pricing_rules')->where('category_id', $id)->doesntExist()) {
                foreach (DB::table('pricing_rules')->where('category_id', $template)->get() as $pr) {
                    $row = (array) $pr;
                    unset($row['id']);
                    $row['category_id'] = $id;
                    $row['created_at'] = $now;
                    $row['updated_at'] = $now;
                    DB::table('pricing_rules')->insert($row);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::ROWS as $r) {
            $id = DB::table('service_categories')->where('code', $r['code'])->value('id');
            if ($id) {
                DB::table('pricing_rules')->where('category_id', $id)->delete();
                DB::table('service_categories')->where('id', $id)->delete();
            }
        }
        if (Schema::hasColumn('match_requests', 'childcare_child_id')) {
            Schema::table('match_requests', function (Blueprint $table) {
                $table->dropConstrainedForeignId('childcare_child_id');
            });
        }
        Schema::dropIfExists('children');
        // enum/SET 값은 append-only 원칙상 축소하지 않음(childcare 잔존 — 무해).
    }
};
