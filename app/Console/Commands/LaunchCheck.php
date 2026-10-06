<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * 서비스 개시 전 전환 점검(CAREN-TODO-01 「4. 서비스 개시 전에 바꿀 것」, 2026-10-07). 읽기만 한다.
 * 12/1 개시 판정(11/27) 때 `php artisan launch:check` 로 7개 항목과 덧붙인 확인 사항을 한 번에 본다.
 * 각 항목은 ✔ 개시 상태 / ✘ 아직 시연 상태, 바꾸는 방법은 줄 끝 → 뒤에 적었다.
 */
class LaunchCheck extends Command
{
    protected $signature = 'launch:check';
    protected $description = '서비스 개시 전 전환 항목(관리자 2단계 인증·비밀번호·데모 계정·시험 인증번호·외부 연동·서류 차단·원격 백업) 상태 점검';

    private int $todo = 0;

    public function handle(): int
    {
        $this->line('케어앤 개시 전 전환 점검 — ' . now('Asia/Seoul')->format('Y-m-d H:i') . ' KST');
        $stub = (bool) config('services.external.stub');

        // 1. 관리자 2단계 인증
        $admins = DB::table('users')->where('role', 'admin')->whereNull('deleted_at')->where('status', 'active')
            ->get(['email', 'password', 'totp_enabled_at']);
        $noTotp = $admins->whereNull('totp_enabled_at')->pluck('email')->all();
        $this->item(1, '관리자 2단계 인증', (bool) config('auth.admin_2fa_required', true),
            config('auth.admin_2fa_required', true) ? '켜짐' : '꺼짐',
            '.env ADMIN_2FA_REQUIRED=true → php artisan config:cache → chmod 644 bootstrap/cache/*.php');
        if ($noTotp) {
            $this->line('     인증 앱 미등록 관리자(켜면 다음 로그인 때 QR 등록): ' . implode(', ', $noTotp));
        }

        // 2. 관리자 기본 비밀번호
        $weak = $admins->filter(fn ($a) => $a->password && (Hash::check('test1234', $a->password) || Hash::check('Demo1234!', $a->password)))
            ->pluck('email')->all();
        $this->item(2, '관리자 기본 비밀번호', !$weak, $weak ? '시험용 값: ' . implode(', ', $weak) : '시험용 값 없음',
            '관리자 계정 화면에서 교체 + storage/app/test_accounts.json 갱신');

        // 3. 데모 공용 비밀번호 계정
        $demo = DB::table('users')->whereNull('deleted_at')->where('status', 'active')->where('email', 'like', '%@demo.careand.kr')
            ->selectRaw('role, count(*) n')->groupBy('role')->pluck('n', 'role');
        $this->item(3, '데모 공용 비밀번호 계정', $demo->sum() === 0,
            $demo->sum() ? '활성 ' . $demo->sum() . '개(' . $demo->map(fn ($n, $r) => "{$r} {$n}")->implode(', ') . ')' : '없음',
            '정리 여부 결정 후 정지·삭제(@demo.careand.kr)');

        // 4. 시험 인증번호 123456 — 스텁 모드 + 테스트 번호(010-0000-)에서만 통과, 스텁을 끄면 같이 꺼진다
        $hints = $this->signupHints();
        $this->item(4, '가입 화면 「123456」·시험 번호 우회', !$stub && !$hints,
            ($stub ? '우회 살아 있음(테스트 번호 ' . config('services.otp.stub_test_prefixes', '0100000') . '…)' : '우회 꺼짐')
                . ($hints ? ' · 안내 문구 ' . count($hints) . '곳: ' . implode(', ', $hints) : ''),
            '안내 문구 삭제(회원웹·공개웹 가입 화면) + EXTERNAL_STUB=false');

        // 5. 외부 연동 모의 모드
        $missing = collect([
            '문자(SMS)' => config('services.sms.api_key'),
            '알림톡' => config('services.alimtalk.token'),
            '건강보험(NHIS)' => config('services.nhis.api_key'),
            '복지부(MOHW)' => config('services.mohw.api_key'),
            '홈택스' => config('services.hometax.api_key'),
        ])->filter(fn ($v) => empty($v))->keys()->all();
        $tossTest = str_starts_with((string) config('services.pg.toss_secret_key'), 'test_');
        $this->item(5, '외부 연동 모의 모드', !$stub,
            ($stub ? '켜짐(실발송 없음)' : '꺼짐') . ($missing ? ' · 빈 키: ' . implode(', ', $missing) : '') . ($tossTest ? ' · 토스 시험 키' : ''),
            '계약·키 반영 뒤 EXTERNAL_STUB=false (키 없는 연동이 있으면 끄는 순간 그 기능이 실패함)');

        // 6. 서류 미비 차단
        $cg = (bool) config('caregiver_docs.enforce_on_approve');
        $mnh = (bool) config('mnh_docs.enforce');
        $this->item(6, '서류 미비 차단', $cg && $mnh,
            '돌봄전문가 승인 ' . ($cg ? '차단' : '경고만') . ' · 산모신생아 서명 ' . ($mnh ? '차단' : '경고만'),
            'CAREGIVER_DOCS_ENFORCE / MNH_DOCS_ENFORCE=true (결정 1번에 따라)');

        // 7. 원격지 백업
        $cron = @file_get_contents('/etc/cron.d/careand') ?: '';
        $offsiteOn = (bool) preg_match('/^\s*[^#\s].*careand-offsite/m', $cron);
        $lastLog = collect(@file('/var/log/careand-backup.log') ?: [])->filter(fn ($l) => str_contains($l, '[offsite'))->last();
        $this->item(7, '원격지 백업(190.159)', $offsiteOn,
            ($offsiteOn ? 'cron 켜짐' : 'cron 멈춤') . ($lastLog ? ' · 마지막: ' . trim(substr($lastLog, 0, 60)) : ' · 기록 없음'),
            '/etc/cron.d/careand 의 #PAUSED-20260925 careand-offsite 줄 주석 해제');

        // 덧붙인 확인 사항
        $this->newLine();
        $this->line('덧붙임');
        $this->item('a', '디버그 끔', !config('app.debug'), config('app.debug') ? 'APP_DEBUG=true' : 'APP_DEBUG=false', 'APP_DEBUG=false');
        $this->item('b', '네이티브 푸시(FCM v1)', (bool) config('services.fcm.v1.enabled'),
            config('services.fcm.v1.enabled') ? '켜짐' : '꺼짐(앱 실기기 확인 뒤)', 'php artisan fcm:v1-check 통과 후 FCM_V1_ENABLED=true');
        $this->item('c', '결제 전 출근 차단', (bool) config('matching_rules.checkin_requires_payment', true),
            config('matching_rules.checkin_requires_payment', true) ? '켜짐' : '꺼짐', 'CHECKIN_REQUIRES_PAYMENT=true');
        $phone = $this->csPhone();
        $this->item('d', '고객센터 번호', $phone !== null && $phone !== '1600-0000',
            $phone ? "회원웹 {$phone}" : '회원웹 설정 못 읽음', '회원웹 NEXT_PUBLIC_CS_PHONE + 앱 빌드 --dart-define CS_PHONE');

        $this->newLine();
        $this->line($this->todo ? "남은 전환 {$this->todo}건" : '모두 개시 상태');
        return self::SUCCESS;
    }

    private function item(int|string $no, string $label, bool $ok, string $state, string $how): void
    {
        if (!$ok) {
            $this->todo++;
        }
        $line = sprintf(' %s %s. %s — %s', $ok ? '✔' : '✘', $no, $label, $state);
        $ok ? $this->info($line) : $this->warn($line . "\n     → {$how}");
    }

    /** 가입 화면에 남은 「123456」 안내 문구 위치 */
    private function signupHints(): array
    {
        $out = [];
        foreach (['회원웹' => '/root/caren/careand-member-web/app/signup/page.tsx', '공개웹' => '/root/caren/careand-www/app/signup/page.tsx'] as $name => $f) {
            $src = @file_get_contents($f);
            if ($src !== false && preg_match('/<b[^>]*>\s*123456\s*<\/b>/', $src)) {
                $out[] = $name;
            }
        }
        return $out;
    }

    /** 회원웹 고객센터 번호 — .env.local 의 NEXT_PUBLIC_CS_PHONE, 없으면 코드 기본값(자리표시) */
    private function csPhone(): ?string
    {
        $env = @file_get_contents('/root/caren/careand-member-web/.env.local');
        if ($env !== false && preg_match('/^NEXT_PUBLIC_CS_PHONE=(.+)$/m', $env, $m)) {
            return trim($m[1], " \"'");
        }
        return is_readable('/root/caren/careand-member-web/lib/support.ts') ? '1600-0000' : null;
    }
}
