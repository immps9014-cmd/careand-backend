<?php

namespace App\Support;

use App\Services\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * 산모신생아 이용자 이용일지(2026-10-05) SSOT — 회원 웹·앱(산모 작성), 관리자 「이용일지」 탭·계약 상세(기관 확인)가 같이 쓴다.
 * 요구사항 「이용자 이용일지 — 수시 작성, 기관 알림」.
 *
 * 기관 알림 규칙(알림 폭주 방지):
 *  - flag 가 붙은 기록(아기 발열·저체온, 산모 발열·컨디션 나쁨, 서비스 의견)은 바로 산모신생아 담당 관리자에게 알린다.
 *  - 그 밖의 기록은 산모별로 하루(한국 날짜) 첫 기록 때 한 번만 알린다.
 * 메모(note)는 건강정보라 MedicalCrypto 로 암호화한다.
 */
final class MnhClientJournal
{
    public const KINDS = [
        'feeding' => '수유',
        'diaper' => '기저귀',
        'sleep' => '수면',
        'temperature' => '체온',
        'mother' => '산모 상태',
        'service' => '서비스 의견',
        'note' => '메모',
    ];

    /** 아기 기록 — 아기가 한 명이면 자동으로 그 아기 */
    public const BABY_KINDS = ['feeding', 'diaper', 'sleep'];

    public const FEEDING_METHODS = ['breast' => '모유(직접)', 'bottle_breast' => '유축 모유', 'formula' => '분유'];

    public const DIAPER_TYPES = ['urine' => '소변', 'stool' => '대변', 'both' => '소변·대변'];

    public const CONDITIONS = ['good' => '좋음', 'ok' => '보통', 'bad' => '안 좋음'];

    public const FLAGS = [
        'baby_fever' => '아기 발열',
        'baby_low_temp' => '아기 저체온',
        'mother_fever' => '산모 발열',
        'mother_bad' => '산모 컨디션 나쁨',
        'service_opinion' => '서비스 의견',
    ];

    // 체온 기준(℃) — 신생아 정상 36.5~37.5, 산모 38.0 이상은 산욕열 의심
    public const BABY_FEVER = 37.5;
    public const BABY_LOW = 36.0;
    public const MOTHER_FEVER = 38.0;

    public static function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(array_keys(self::KINDS))],
            'newborn_id' => ['nullable', 'integer'],
            'logged_at' => ['nullable', 'date'],
            'values' => ['nullable', 'array'],
            'values.method' => ['nullable', Rule::in(array_keys(self::FEEDING_METHODS))],
            'values.ml' => ['nullable', 'integer', 'between:0,500'],
            'values.minutes' => ['nullable', 'integer', 'between:1,1440'],
            'values.type' => ['nullable', Rule::in(array_keys(self::DIAPER_TYPES))],
            'values.target' => ['nullable', Rule::in(['baby', 'mother'])],
            'values.celsius' => ['nullable', 'numeric', 'between:34,42'],
            'values.condition' => ['nullable', Rule::in(array_keys(self::CONDITIONS))],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * 종류별 필수값 확인·정리. 잘못되면 [null, 메시지].
     * @return array{0: ?array, 1: ?string}
     */
    public static function normalize(string $kind, array $values, ?string $note): array
    {
        $note = trim((string) $note) ?: null;
        $v = match ($kind) {
            'feeding' => array_filter([
                'method' => $values['method'] ?? null,
                'ml' => isset($values['ml']) ? (int) $values['ml'] : null,
                'minutes' => isset($values['minutes']) ? (int) $values['minutes'] : null,
            ], fn ($x) => $x !== null),
            'diaper' => array_filter(['type' => $values['type'] ?? null]),
            'sleep' => array_filter(['minutes' => isset($values['minutes']) ? (int) $values['minutes'] : null]),
            'temperature' => array_filter([
                'target' => $values['target'] ?? 'baby',
                'celsius' => isset($values['celsius']) ? round((float) $values['celsius'], 1) : null,
            ], fn ($x) => $x !== null),
            'mother' => array_filter(['condition' => $values['condition'] ?? null]),
            default => [],
        };

        $missing = match ($kind) {
            'feeding' => empty($v['method']) ? '수유 방법을 골라 주세요.' : null,
            'diaper' => empty($v['type']) ? '소변·대변을 골라 주세요.' : null,
            'sleep' => empty($v['minutes']) ? '잠잔 시간을 입력해 주세요.' : null,
            'temperature' => !isset($v['celsius']) ? '체온을 입력해 주세요.' : null,
            'mother' => empty($v['condition']) ? '오늘 컨디션을 골라 주세요.' : null,
            'service', 'note' => $note === null ? '내용을 적어 주세요.' : null,
            default => null,
        };

        return $missing ? [null, $missing] : [$v ?: null, null];
    }

    public static function flagFor(string $kind, ?array $v): ?string
    {
        if ($kind === 'temperature' && isset($v['celsius'])) {
            if (($v['target'] ?? 'baby') === 'mother') {
                return $v['celsius'] >= self::MOTHER_FEVER ? 'mother_fever' : null;
            }

            return $v['celsius'] >= self::BABY_FEVER ? 'baby_fever' : ($v['celsius'] < self::BABY_LOW ? 'baby_low_temp' : null);
        }
        if ($kind === 'mother' && ($v['condition'] ?? null) === 'bad') {
            return 'mother_bad';
        }

        return $kind === 'service' ? 'service_opinion' : null;
    }

    /** 한 줄 요약 — 목록·알림 공용 */
    public static function summary(string $kind, ?array $v): string
    {
        $v ??= [];

        return match ($kind) {
            'feeding' => trim(implode(' · ', array_filter([
                self::FEEDING_METHODS[$v['method'] ?? ''] ?? null,
                isset($v['ml']) ? $v['ml'] . 'ml' : null,
                isset($v['minutes']) ? $v['minutes'] . '분' : null,
            ]))),
            'diaper' => self::DIAPER_TYPES[$v['type'] ?? ''] ?? '',
            'sleep' => isset($v['minutes']) ? self::minutes((int) $v['minutes']) : '',
            'temperature' => (($v['target'] ?? 'baby') === 'mother' ? '산모 ' : '아기 ') . ($v['celsius'] ?? '?') . '℃',
            'mother' => '컨디션 ' . (self::CONDITIONS[$v['condition'] ?? ''] ?? ''),
            default => '',
        };
    }

    private static function minutes(int $m): string
    {
        return $m >= 60 ? intdiv($m, 60) . '시간' . ($m % 60 ? ' ' . ($m % 60) . '분' : '') : $m . '분';
    }

    /** 응답 행. $admin 이면 확인자 이름까지 */
    public static function present(object $r, array $babyNames = [], array $staff = []): array
    {
        $values = is_string($r->values) ? json_decode($r->values, true) : ($r->values ?? null);

        return [
            'id' => (int) $r->id,
            'kind' => $r->kind,
            'kind_label' => self::KINDS[$r->kind] ?? $r->kind,
            'newborn_id' => $r->newborn_id ? (int) $r->newborn_id : null,
            'newborn_name' => $r->newborn_id ? ($babyNames[$r->newborn_id] ?? null) : null,
            'logged_at' => Kst::iso($r->logged_at),
            'values' => $values,
            'summary' => self::summary($r->kind, $values),
            'note' => MedicalCrypto::decrypt($r->note),
            'flag' => $r->flag,
            'flag_label' => $r->flag ? (self::FLAGS[$r->flag] ?? $r->flag) : null,
            'checked_at' => Kst::iso($r->checked_at),
            'checked_by_name' => $r->checked_by ? ($staff[$r->checked_by] ?? null) : null,
            'check_note' => $r->check_note ?? null,
            'created_at' => Kst::iso($r->created_at),
        ];
    }

    public static function options(): array
    {
        return [
            'kinds' => self::KINDS,
            'feeding_methods' => self::FEEDING_METHODS,
            'diaper_types' => self::DIAPER_TYPES,
            'conditions' => self::CONDITIONS,
            'flags' => self::FLAGS,
            'thresholds' => ['baby_fever' => self::BABY_FEVER, 'baby_low' => self::BABY_LOW, 'mother_fever' => self::MOTHER_FEVER],
        ];
    }

    /** 기관 알림 — flag 면 바로, 아니면 산모별 하루 첫 기록만 */
    public static function notifyOrg(int $journalId): void
    {
        $j = DB::table('mnh_client_journals')->where('id', $journalId)->first();
        if (!$j) {
            return;
        }
        if (!$j->flag) {
            $dayStart = Carbon::now(Kst::TZ)->startOfDay()->utc();
            $earlier = DB::table('mnh_client_journals')->where('postpartum_client_id', $j->postpartum_client_id)
                ->where('id', '<', $j->id)->where('created_at', '>=', $dayStart)->exists();
            if ($earlier) {
                return;
            }
        }
        $name = (string) DB::table('postpartum_clients')->where('id', $j->postpartum_client_id)->value('name');
        $contract = DB::table('mnh_contracts')->where('postpartum_client_id', $j->postpartum_client_id)
            ->whereIn('status', ['applied', 'confirmed', 'active'])->orderByDesc('id')->first(['id', 'contract_no']);
        $values = json_decode((string) $j->values, true);
        $payload = [
            'journal_id' => (int) $j->id,
            'postpartum_client_id' => (int) $j->postpartum_client_id,
            'client_name' => $name !== '' ? mb_substr($name, 0, 1) . '○○' : '산모',
            'kind_label' => self::KINDS[$j->kind] ?? $j->kind,
            'summary' => self::summary($j->kind, $values),
            'flag' => $j->flag,
            'flag_label' => $j->flag ? (self::FLAGS[$j->flag] ?? $j->flag) : null,
            'contract_id' => $contract ? (int) $contract->id : null,
            'contract_no' => $contract->contract_no ?? null,
        ];
        $svc = app(NotificationService::class);
        foreach ($svc->adminsFor('mnh-journals') as $adminUserId) {
            $svc->notifySafely($adminUserId, NotificationService::TYPE_MNH_CLIENT_JOURNAL, $payload);
        }
    }
}
