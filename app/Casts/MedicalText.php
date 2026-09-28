<?php

namespace App\Casts;

use App\Support\MedicalCrypto;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;

/** 의료정보 문자열 — DB 에는 AES-256-GCM 암호문, 코드에서는 평문 (S2-4) */
class MedicalText implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes)
    {
        return MedicalCrypto::decrypt($value);
    }

    public function set($model, string $key, $value, array $attributes)
    {
        return $value === null ? null : MedicalCrypto::encrypt((string) $value);
    }
}
