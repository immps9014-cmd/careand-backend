<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 매칭 확정 시 돌봄전문가의 기존 일정과 시간이 겹칠 때 던진다(기능 11·21, 2026-09-28 S5).
 */
class ScheduleConflictException extends RuntimeException
{
}
