<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 4 — 마음돌봄(mental_care) 오픈.
     *  1) mental_care_clients 대상 테이블(보호자 소유, children 패턴 + home_lat/lng).
     *  2) enum/SET 4곳에 'mental_care' append (ALGORITHM=INSTANT).
     *  3) match_requests.mental_care_client_id FK.
     *  4) 카테고리(MC_*) + pricing_rules 시드(요율 잠정).
     *
     * 자격: 상담 도메인은 무자격 등록 → 관리자 수동 승인(기존 caregiver register 흐름 그대로).
     * 설계서: /root/CAREAND-DOMAIN-INTEGRATION.md (Phase 4)
     */
    private const ROWS = [
        ['code' => 'MC_SUPPORT',   'name' => '정서지원',     'base_rate' => 16000, 'description' => '가정 방문 정서지원·말벗·심리 안정 돌봄'],
        ['code' => 'MC_COMPANION', 'name' => '심리상담 동행', 'base_rate' => 16000, 'description' => '심리상담·정신건강의학과 방문 동행 지원'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('mental_care_clients')) {
            Schema::create('mental_care_clients', function (Blueprint $table) {
                $table->id();
                $table->foreignId('guardian_id')->constrained('guardians');
                $table->string('name', 50);
                $table->date('birth_date')->nullable();
                $table->enum('gender', ['M', 'F'])->nullable();
                $table->string('relation', 20)->nullable()->comment('본인/가족 관계');
                $table->string('home_address', 255);
                $table->decimal('home_lat', 10, 7)->nullable();
                $table->decimal('home_lng', 10, 7)->nullable();
                $table->string('special_notes', 500)->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index('home_lat');
            });
        }

        DB::statement("ALTER TABLE match_requests
            MODIFY service_domain ENUM('senior','postpartum','nursing','housekeeping','living_support','childcare','mental_care')
            NOT NULL DEFAULT 'senior' COMMENT '서비스 도메인', ALGORITHM=INSTANT");
        DB::statement("ALTER TABLE caregivers
            MODIFY service_domains SET('senior','postpartum','nursing','housekeeping','living_support','childcare','mental_care')
            NOT NULL DEFAULT 'senior' COMMENT '활동 도메인 SET', ALGORITHM=INSTANT");
        DB::statement("ALTER TABLE mock_interviews
            MODIFY target_domain ENUM('senior','postpartum','nursing','housekeeping','living_support','childcare','mental_care')
            NOT NULL, ALGORITHM=INSTANT");
        DB::statement("ALTER TABLE self_introduction_interviews
            MODIFY extracted_target_domain ENUM('senior','postpartum','both','nursing','housekeeping','living_support','childcare','mental_care')
            NULL DEFAULT NULL, ALGORITHM=INSTANT");

        if (!Schema::hasColumn('match_requests', 'mental_care_client_id')) {
            Schema::table('match_requests', function (Blueprint $table) {
                $table->foreignId('mental_care_client_id')->nullable()->after('childcare_child_id')
                    ->comment('마음돌봄 대상 ID (mental_care 도메인 시)')
                    ->constrained('mental_care_clients');
            });
        }

        $now = now();
        $template = DB::table('service_categories')->where('code', 'COMPANION')->value('id');
        foreach (self::ROWS as $r) {
            $id = DB::table('service_categories')->where('code', $r['code'])->value('id');
            if (!$id) {
                $id = DB::table('service_categories')->insertGetId([
                    'code' => $r['code'], 'domain' => 'mental_care', 'name' => $r['name'],
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
        if (Schema::hasColumn('match_requests', 'mental_care_client_id')) {
            Schema::table('match_requests', function (Blueprint $table) {
                $table->dropConstrainedForeignId('mental_care_client_id');
            });
        }
        Schema::dropIfExists('mental_care_clients');
        // enum/SET 값은 append-only 원칙상 축소하지 않음.
    }
};
