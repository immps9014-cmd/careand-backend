<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CareActivity extends Model
{
    use HasFactory;

    protected $table = 'care_activities';

    protected $fillable = [
        'session_id',
        'category',
        'data',
        'memo',
        'performed_at',
    ];

    protected $casts = [
        'data' => 'array',
        'performed_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(CareSession::class, 'session_id');
    }

}