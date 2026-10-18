<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * 건강정보(민감정보) 별도 동의 — 개인정보 동의서 v4 의 sensitive_consent(2026-10-18).
 * 운영사 결정: 동의하지 않은 산모는 서비스·서류·일정은 그대로 진행하고 「건강 기록만 빼고」 남긴다.
 *   - 제공기록지: 건강 관찰 칸(config mnh_docs 의 'health' => true)을 보여 주지도 저장하지도 않는다
 *   - 이용일지: 체온·산모 상태 기록을 받지 않는다(수유·기저귀·수면·서비스 의견·메모는 그대로)
 *   - 산후우울 검사(EPDS): 응시를 받지 않는다
 *   - 서류 본문: 분만 형태·출생 체중 자리에 「건강정보 동의 안 함」
 * 판단 기준: 그 산모의 계약들에서 가장 최근에 서명한 개인정보 동의서. 아직 서명 전이면 거부가 아니다.
 */
final class MnhHealthConsent
{
    public const KINDS_BLOCKED = ['temperature', 'mother'];
    public const MESSAGE = '건강정보 수집·이용에 동의하지 않아 이 기록은 남기지 않아요. 동의하려면 운영팀에 알려 주세요.';

    private static array $memo = [];

    /** 이 산모가 건강정보 수집·이용에 동의하지 않았는가 */
    public static function refused(?int $postpartumClientId): bool
    {
        if (!$postpartumClientId) {
            return false;
        }
        if (array_key_exists($postpartumClientId, self::$memo)) {
            return self::$memo[$postpartumClientId];
        }
        $form = DB::table('mnh_documents as d')->join('mnh_contracts as c', 'c.id', '=', 'd.contract_id')
            ->where('c.postpartum_client_id', $postpartumClientId)->where('d.doc_type', 'privacy_consent')->where('d.status', 'signed')
            ->orderByDesc('d.signed_at')->orderByDesc('d.id')->value('d.form_data');
        $data = is_string($form) ? json_decode($form, true) : (array) $form;

        return self::$memo[$postpartumClientId] = (($data['sensitive_consent'] ?? null) === 'disagree');
    }

    /** 계약 id 로 */
    public static function refusedForContract(?int $contractId): bool
    {
        return $contractId ? self::refused((int) DB::table('mnh_contracts')->where('id', $contractId)->value('postpartum_client_id')) : false;
    }

    /** 서명이 바뀌면 다시 읽게(같은 요청 안에서 동의서를 막 서명한 경우) */
    public static function forget(): void
    {
        self::$memo = [];
    }
}
