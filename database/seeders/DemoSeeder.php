<?php

namespace Database\Seeders;

use App\Models\AiModel;
use App\Models\AnomalyAlert;
use App\Models\CareMatch;
use App\Models\Caregiver;
use App\Models\CareSession;
use App\Models\ChatbotMessage;
use App\Models\ChatbotSession;
use App\Models\Guardian;
use App\Models\HealthTimeseries;
use App\Models\LtcVoucher;
use App\Models\MatchCandidate;
use App\Models\MatchRequest;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\Senior;
use App\Models\ServiceCategory;
use App\Models\Settlement;
use App\Models\User;
use App\Models\VitalRecord;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * 데모 데이터 시더 — 운영 admin 화면 KPI/차트/표 채우기 용
 *
 * 사용:
 *   php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    private const GUARDIAN_NAMES = [
        '김상철', '박지현', '이민호', '최수진', '정현우',
        '강은정', '윤재석', '한가영', '오태경', '서지원',
    ];
    private const SENIOR_NAMES = [
        '홍어머님', '박할머님', '이아버님', '정할아버지', '최할머님',
        '송할아버지', '강할머님', '문어머님', '백아버님', '손할머님',
        '권할아버지', '한어머님', '신할머님', '구아버지', '하할머님',
    ];
    private const CAREGIVER_NAMES = [
        '이정희', '김순자', '박미경', '최영숙', '정연희',
        '윤혜진', '강민지', '오수정', '서민정', '한지숙',
        '문경아', '백선영', '송미라', '양진주', '권혜영',
        '신은영', '구미영', '조선희', '임나영', '곽지은',
    ];
    private const SEOUL_AREAS = [
        ['name' => '강남구', 'lat' => 37.5172, 'lng' => 127.0473],
        ['name' => '서초구', 'lat' => 37.4837, 'lng' => 127.0324],
        ['name' => '송파구', 'lat' => 37.5145, 'lng' => 127.1058],
        ['name' => '마포구', 'lat' => 37.5663, 'lng' => 126.9019],
        ['name' => '용산구', 'lat' => 37.5326, 'lng' => 126.9905],
        ['name' => '성북구', 'lat' => 37.5894, 'lng' => 127.0167],
    ];
    private const DISEASES_POOL = [
        ['고혈압'], ['당뇨'], ['관절염'], ['치매', '경증치매'],
        ['파킨슨'], ['뇌졸중 후유증'], ['고혈압', '당뇨'], ['우울증'], [],
    ];
    private const SPECIALTIES_POOL = [
        ['치매'], ['당뇨'], ['욕창'], ['파킨슨'], ['투석'],
        ['치매', '욕창'], ['당뇨', '고혈압'], [],
    ];

    public function run(): void
    {
        $this->command->info('🌱 DemoSeeder 시작…');

        $this->seedAiModels();
        [$guardians, $caregivers, $seniors] = $this->seedUsersAndProfiles();
        $this->seedVouchers($seniors);
        [$requests, $matches] = $this->seedMatchingAndSessions($guardians, $caregivers, $seniors);
        $this->seedHealthData($seniors);
        $this->seedPaymentsAndSettlements($matches, $caregivers);
        $this->seedChatbot($guardians);
        $this->seedNotifications();

        $this->command->info('✅ 데모 데이터 시드 완료');
        $this->printSummary();
    }

    // ─────────────────────────────────────────────────────────────

    private function seedAiModels(): void
    {
        if (AiModel::count() > 0) return;

        $models = [
            ['model_name' => 'matching-recommender',  'version' => 'v1.2.0', 'status' => 'active',     'accuracy' => 0.8740, 'avg_latency_ms' => 142.3],
            ['model_name' => 'matching-recommender',  'version' => 'v1.3.0-beta', 'status' => 'shadow', 'accuracy' => 0.8920, 'avg_latency_ms' => 138.7],
            ['model_name' => 'anomaly-detector',      'version' => 'v0.8.1', 'status' => 'active',     'accuracy' => 0.9120, 'avg_latency_ms' => 87.5],
            ['model_name' => 'voice-summary-llm',     'version' => 'v2.0.0', 'status' => 'active',     'accuracy' => 0.9450, 'avg_latency_ms' => 2143.0],
            ['model_name' => 'demand-forecast',       'version' => 'v0.5.0', 'status' => 'active',     'accuracy' => 0.8210, 'avg_latency_ms' => 65.2],
            ['model_name' => 'rag-chatbot',           'version' => 'v1.0.0', 'status' => 'active',     'accuracy' => 0.8830, 'avg_latency_ms' => 1820.0],
        ];

        foreach ($models as $m) {
            AiModel::create(array_merge($m, [
                'metadata' => ['training_dataset' => 'demo-2026-Q2', 'gpu' => 'A10G'],
                'audited_at' => now()->subDays(rand(3, 30)),
            ]));
        }
    }

    private function seedUsersAndProfiles(): array
    {
        // 보호자
        $guardians = [];
        foreach (self::GUARDIAN_NAMES as $i => $name) {
            $user = User::create([
                'email' => "guardian{$i}@demo.careand.kr",
                'phone' => '010' . str_pad((string) (10000000 + $i * 11), 8, '0', STR_PAD_LEFT),
                'name' => $name,
                'role' => 'guardian',
                'password' => 'Demo1234!',  // User 모델 hashed 캐스트가 해싱 (Hash::make 중첩 금지)
                'phone_verified_at' => now()->subDays(rand(30, 200)),
                'email_verified_at' => now()->subDays(rand(30, 200)),
                'status' => 'active',
            ]);
            $guardians[] = Guardian::create([
                'user_id' => $user->id,
                'relation' => collect(['자녀', '배우자', '며느리', '사위'])->random(),
                'contact_address' => '서울시 ' . self::SEOUL_AREAS[array_rand(self::SEOUL_AREAS)]['name'] . ' ' . rand(100, 999) . '-' . rand(1, 50),
            ]);
        }

        // 어르신
        $seniors = [];
        foreach (self::SENIOR_NAMES as $i => $name) {
            $g = $guardians[$i % count($guardians)];
            $area = self::SEOUL_AREAS[$i % count(self::SEOUL_AREAS)];
            $seniors[] = Senior::create([
                'guardian_id' => $g->id,
                'name' => $name,
                'birth_date' => now()->subYears(rand(70, 92))->subMonths(rand(0, 11))->toDateString(),
                'gender' => rand(0, 1) ? 'F' : 'M',
                'care_grade' => collect(['1', '2', '3', '4', '5', 'cognitive'])->random(),
                'diseases' => self::DISEASES_POOL[array_rand(self::DISEASES_POOL)],
                'home_address' => '서울시 ' . $area['name'] . ' ' . rand(100, 999) . '-' . rand(1, 50),
                'home_lat' => $area['lat'] + (rand(-200, 200) / 10000),
                'home_lng' => $area['lng'] + (rand(-200, 200) / 10000),
            ]);
        }

        // 인력 — 70%는 active, 20%는 pending(승인대기), 10%는 rejected
        $caregivers = [];
        foreach (self::CAREGIVER_NAMES as $i => $name) {
            $user = User::create([
                'email' => "caregiver{$i}@demo.careand.kr",
                'phone' => '010' . str_pad((string) (20000000 + $i * 11), 8, '0', STR_PAD_LEFT),
                'name' => $name,
                'role' => 'caregiver',
                'password' => 'Demo1234!',  // User 모델 hashed 캐스트가 해싱 (Hash::make 중첩 금지)
                'phone_verified_at' => now()->subDays(rand(30, 200)),
                'status' => 'active',
            ]);

            $area = self::SEOUL_AREAS[$i % count(self::SEOUL_AREAS)];
            $status = $i < 14 ? 'active' : ($i < 18 ? 'pending' : 'rejected');

            $caregivers[] = Caregiver::create([
                'user_id' => $user->id,
                'birth_date' => now()->subYears(rand(35, 65))->toDateString(),
                'gender' => 'F',
                'license_no' => 'YY' . str_pad((string) (100000 + $i), 6, '0', STR_PAD_LEFT),
                'license_issued_at' => now()->subYears(rand(2, 15))->toDateString(),
                'license_verified_at' => $status === 'active' ? now()->subDays(rand(30, 300)) : null,
                'specialties' => self::SPECIALTIES_POOL[array_rand(self::SPECIALTIES_POOL)],
                'base_address' => '서울시 ' . $area['name'] . ' ' . rand(100, 999) . '-' . rand(1, 50),
                'base_lat' => $area['lat'] + (rand(-300, 300) / 10000),
                'base_lng' => $area['lng'] + (rand(-300, 300) / 10000),
                'rating_avg' => round(3.5 + (rand(0, 150) / 100), 2),
                'rating_count' => rand(0, 80),
                'completed_sessions' => rand(0, 200),
                'grade_level' => rand(1, 5),
                'status' => $status,
                'rejection_reason' => $status === 'rejected' ? '범죄경력회보서 미제출' : null,
            ]);
        }

        return [$guardians, $caregivers, $seniors];
    }

    private function seedVouchers(array $seniors): void
    {
        $limits = ['1' => 1985200, '2' => 1769200, '3' => 1455800, '4' => 1341900, '5' => 1151600, 'cognitive' => 1455800];
        foreach ($seniors as $s) {
            if (!isset($limits[$s->care_grade])) continue;
            $limit = $limits[$s->care_grade];
            $used = rand((int)($limit * 0.2), (int)($limit * 0.85));
            LtcVoucher::create([
                'senior_id'        => $s->id,
                'period_month'     => now()->startOfMonth()->toDateString(),
                'monthly_limit'    => $limit,
                'used_amount'      => $used,
                'remaining_amount' => $limit - $used,
                'copay_rate'       => 15,
            ]);
        }
    }

    private function seedMatchingAndSessions(array $guardians, array $caregivers, array $seniors): array
    {
        $cats = ServiceCategory::all();
        $activeCaregivers = collect($caregivers)->where('status', 'active')->values();
        if ($activeCaregivers->isEmpty()) return [[], []];

        $requests = [];
        $matches = [];

        // 30개 매칭 요청 생성 (다양한 시점/상태)
        for ($i = 0; $i < 30; $i++) {
            $senior = $seniors[$i % count($seniors)];
            $cat = $cats->random();
            // 시점: -14일 ~ +7일 (오늘 포함). 일부는 오늘
            $offsetDays = $i < 5 ? 0 : rand(-14, 7);
            $start = now()->addDays($offsetDays)->setTime(rand(8, 18), [0, 30][rand(0, 1)]);

            // 상태 분포: 50% matched/in_progress/completed, 30% open, 20% cancelled
            $status = match (true) {
                $i < 3 => 'open',
                $i < 18 => 'matched',
                $i < 25 => 'completed',
                default => 'cancelled',
            };

            $req = MatchRequest::create([
                'guardian_id'      => $senior->guardian_id,
                'senior_id'        => $senior->id,
                'category_id'      => $cat->id,
                'mode'             => collect(['one_time', 'recurring'])->random(),
                'scheduled_start'  => $start,
                'duration_min'     => collect([60, 120, 180, 240])->random(),
                'special_request'  => collect([null, '말동무 위주', '식사 보조', '병원 동행', null])->random(),
                'status'           => $status,
                'matched_at'       => in_array($status, ['matched', 'completed']) ? $start->copy()->subHours(rand(2, 24)) : null,
            ]);
            $requests[] = $req;

            // 후보 5명 (open 외)
            if ($status === 'open') {
                $cands = $activeCaregivers->random(min(5, $activeCaregivers->count()));
                foreach ($cands as $idx => $c) {
                    MatchCandidate::create([
                        'request_id'   => $req->id,
                        'caregiver_id' => $c->id,
                        'ai_score'     => round(0.95 - $idx * 0.04, 3),
                        'ai_reasons'   => ['평점 ' . $c->rating_avg, '특기 적합', '근거리'],
                        'rank'         => $idx + 1,
                        'response'     => 'pending',
                    ]);
                }
            }

            // 매칭된 요청은 CareMatch 생성
            if (in_array($status, ['matched', 'completed'])) {
                $caregiver = $activeCaregivers->random();
                $end = $start->copy()->addMinutes($req->duration_min);
                $rate = $cat->base_rate;
                $estimated = (int) round($rate * $req->duration_min / 60);

                $match = CareMatch::create([
                    'request_id'        => $req->id,
                    'caregiver_id'      => $caregiver->id,
                    'scheduled_start'   => $start,
                    'scheduled_end'     => $end,
                    'hourly_rate'       => $rate,
                    'estimated_amount'  => $estimated,
                    'status'            => $status === 'completed' ? 'completed' : 'confirmed',
                ]);
                $matches[] = $match;

                // 케어 세션 생성 (completed인 경우 actual_start/end 채움)
                CareSession::create([
                    'match_id'     => $match->id,
                    'actual_start' => $status === 'completed' ? $start : null,
                    'actual_end'   => $status === 'completed' ? $end : null,
                    'duration_min' => $status === 'completed' ? $req->duration_min : null,
                    'status'       => $status === 'completed' ? 'completed' : 'scheduled',
                ]);
            }
        }

        return [$requests, $matches];
    }

    private function seedHealthData(array $seniors): void
    {
        // 어르신별 vitals 14일치 + timeseries
        foreach ($seniors as $s) {
            for ($d = 13; $d >= 0; $d--) {
                $when = now()->subDays($d)->setTime(rand(9, 18), [0, 30][rand(0, 1)]);
                VitalRecord::create([
                    'senior_id'          => $s->id,
                    'blood_pressure_sys' => rand(110, 145),
                    'blood_pressure_dia' => rand(70, 95),
                    'blood_sugar'        => rand(85, 145),
                    'body_temperature'   => 36.0 + (rand(0, 12) / 10),
                    'heart_rate'         => rand(64, 88),
                    'measured_at'        => $when,
                ]);

                foreach (['meal_pct' => rand(40, 100), 'sleep_hours' => round(5 + rand(0, 50) / 10, 1), 'mood_score' => rand(50, 95)] as $metric => $val) {
                    HealthTimeseries::create([
                        'senior_id'   => $s->id,
                        'metric_name' => $metric,
                        'value'       => $val,
                        'recorded_at' => $when,
                    ]);
                }
            }
        }

        // 이상징후 알림 — 일부 어르신에게
        $alertSeniors = collect($seniors)->random(8);
        $now = now();
        foreach ($alertSeniors as $idx => $s) {
            $riskType = collect(['fall', 'delirium', 'depression', 'nutrition'])->random();
            $severity = $idx < 3 ? 'critical' : ($idx < 5 ? 'high' : ($idx < 7 ? 'mid' : 'low'));
            $score = ['critical' => 90 + rand(0, 9), 'high' => 75 + rand(0, 14), 'mid' => 50 + rand(0, 20), 'low' => 20 + rand(0, 25)][$severity];

            AnomalyAlert::create([
                'senior_id'       => $s->id,
                'risk_type'       => $riskType,
                'risk_score'      => $score,
                'severity'        => $severity,
                'trigger_pattern' => ['3일 연속 식사량 50% 이하', '평균 수면 5시간 미만'],
                'recommendation'  => ['수분/영양 보충식 권장', '의료진 상담 권장'],
                'status'          => $idx < 5 ? 'new' : 'resolved',
                'detected_at'     => $now->copy()->subHours(rand(1, 72)),
                'resolved_at'     => $idx >= 5 ? $now->copy()->subHours(rand(0, 24)) : null,
            ]);
        }
    }

    private function seedPaymentsAndSettlements(array $matches, array $caregivers): void
    {
        $completed = collect($matches)->where('status', 'completed')->values();
        foreach ($completed as $m) {
            $req = $m->request;
            $self = (int) round($m->estimated_amount * 0.15);
            $ltc  = $m->estimated_amount - $self;
            Payment::create([
                'guardian_id'      => $req->guardian_id,
                'match_id'         => $m->id,
                'total_amount'     => $m->estimated_amount,
                'amount_self_pay'  => $self,
                'amount_ltc_pay'   => $ltc,
                'method'           => 'card',
                'pg_provider'      => 'kg_inicis',
                'pg_tid'           => 'DEMO' . rand(100000, 999999),
                'idempotency_key'  => 'idem-' . $m->id,
                'status'           => 'paid',
                'paid_at'          => $m->scheduled_end,
            ]);
        }

        // 인력별 정산 (이번 주)
        $weekStart = now()->startOfWeek();
        $weekEnd = now()->endOfWeek();
        $byCaregiver = $completed->groupBy('caregiver_id');
        foreach ($byCaregiver as $caregiverId => $list) {
            $gross = $list->sum('estimated_amount');
            $tax = (int) round($gross * 0.033);
            Settlement::create([
                'caregiver_id'        => $caregiverId,
                'period_start'        => $weekStart->toDateString(),
                'period_end'          => $weekEnd->toDateString(),
                'gross_amount'        => $gross,
                'withholding_tax_3_3' => $tax,
                'net_amount'          => $gross - $tax,
                'status'              => collect(['draft', 'confirmed', 'paid'])->random(),
            ]);
        }
    }

    private function seedChatbot(array $guardians): void
    {
        $sampleQuestions = [
            ['Q' => '어머님 4등급이고 본인부담금 15%인데 월 한도액이 얼마인가요?', 'A' => '장기요양 4등급의 월 한도액은 1,341,900원입니다. 본인부담률 15% 기준 약 201,285원이 본인부담입니다.'],
            ['Q' => '병원 동행 서비스도 바우처 적용되나요?', 'A' => '네, 동행 서비스도 장기요양 한도 내에서 바우처로 결제 가능합니다.'],
            ['Q' => '치매 케어 잘하시는 분 매칭하려면?', 'A' => '매칭 요청 시 \'특기: 치매\'로 필터링하면 적합한 인력이 우선 추천됩니다.'],
        ];

        foreach (collect($guardians)->random(5) as $g) {
            $session = ChatbotSession::create([
                'guardian_id' => $g->id,
                'topic'       => '장기요양 문의',
                'started_at'  => now()->subDays(rand(0, 7)),
                'ended_at'    => null,
            ]);

            ChatbotMessage::create([
                'session_id' => $session->id,
                'role'       => 'assistant',
                'content'    => "안녕하세요 {$g->user->name} 님 👋\n어르신 케어 관련 무엇이든 물어보세요.",
            ]);

            foreach ($sampleQuestions as $qa) {
                ChatbotMessage::create(['session_id' => $session->id, 'role' => 'user',      'content' => $qa['Q']]);
                ChatbotMessage::create(['session_id' => $session->id, 'role' => 'assistant', 'content' => $qa['A'], 'sources' => [['title' => '장기요양보험 안내', 'url' => 'https://www.longtermcare.or.kr']]]);
            }
        }
    }

    private function seedNotifications(): void
    {
        $users = User::whereIn('role', ['guardian', 'caregiver'])->inRandomOrder()->take(20)->get();
        $types = [
            ['MATCH_CONFIRMED',     '매칭 확정',         '인력이 케어를 수락했어요.'],
            ['CARE_COMPLETED',      '케어 완료',         '오늘 케어가 완료되었습니다.'],
            ['ANOMALY_HIGH',        'AI 이상징후 감지', '⚠ 영양 위험 78점 — 확인이 필요합니다.'],
            ['PAYMENT_PAID',        '결제 완료',         '180,000원 결제가 완료되었어요.'],
            ['SETTLEMENT_CONFIRMED', '정산서 확정',       '이번 주 정산이 확정되었어요.'],
        ];

        foreach ($users as $u) {
            $count = rand(1, 4);
            for ($i = 0; $i < $count; $i++) {
                [$type, $title, $body] = $types[array_rand($types)];
                Notification::create([
                    'user_id' => $u->id,
                    'type'    => $type,
                    'title'   => $title,
                    'body'    => $body,
                    'is_read' => rand(0, 1) === 1,
                    'sent_at' => now()->subHours(rand(1, 72)),
                    'read_at' => null,
                ]);
            }
        }
    }

    private function printSummary(): void
    {
        $this->command->info('  ─── 데이터 요약 ───');
        $this->command->info('  Users:         ' . User::count());
        $this->command->info('  Guardians:     ' . Guardian::count());
        $this->command->info('  Caregivers:    ' . Caregiver::count() . ' (active=' . Caregiver::where('status','active')->count() . ', pending=' . Caregiver::where('status','pending')->count() . ')');
        $this->command->info('  Seniors:       ' . Senior::count());
        $this->command->info('  MatchRequests: ' . MatchRequest::count());
        $this->command->info('  Matches:       ' . CareMatch::count());
        $this->command->info('  CareSessions:  ' . CareSession::count() . ' (completed=' . CareSession::where('status','completed')->count() . ')');
        $this->command->info('  VitalRecords:  ' . VitalRecord::count());
        $this->command->info('  AnomalyAlerts: ' . AnomalyAlert::count() . ' (open=' . AnomalyAlert::where('status','new')->count() . ')');
        $this->command->info('  Payments:      ' . Payment::count());
        $this->command->info('  Settlements:   ' . Settlement::count());
        $this->command->info('  AiModels:      ' . AiModel::count());
        $this->command->info('  Notifications: ' . Notification::count());
        $this->command->info('  Chatbot sess:  ' . ChatbotSession::count() . ' / msgs=' . ChatbotMessage::count());
    }
}
