<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 서류 서식 판(版) — 고치면 새 판, 발행된 서류는 당시 본문을 그대로 가진다 */
class MnhDocTemplate extends Model
{
    protected $fillable = ['doc_type', 'version', 'title', 'body', 'note', 'created_by'];
}
