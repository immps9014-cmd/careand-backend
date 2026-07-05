<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 동시 수락/선택 경합에서 선착순에 밀렸을 때 던진다.
 * 이미 다른 돌봄전문가로 매칭이 확정된(또는 요청이 종료된) 경우.
 */
class MatchAlreadyTakenException extends RuntimeException
{
    public function __construct(string $message = '이미 다른 돌봄전문가에게 매칭되었습니다.')
    {
        parent::__construct($message);
    }
}
