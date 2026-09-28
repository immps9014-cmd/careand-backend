<?php

namespace App\Casts;

use App\Support\MedicalCrypto;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/** 의료정보 배열(JSON) — 기존 'array' 캐스트와 같은 값을 주고받되 DB 에는 암호문 (S2-4) */
class MedicalJson implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        if ($value === null) {
            return null;
        }
        $plain = MedicalCrypto::decrypt($value);
        $decoded = json_decode((string) $plain, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function set($model, string $key, $value, array $attributes)
    {
        return $value === null ? null : MedicalCrypto::encrypt(json_encode($value, JSON_UNESCAPED_UNICODE));
    }
}
