<?php

namespace App\Exceptions;

/** 바우처 계약 처리 거절 — 컨트롤러가 {error_code, message} 422(또는 status)로 돌려준다. */
class MnhContractException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $extra = [],
    ) {
        parent::__construct($message);
    }
}
