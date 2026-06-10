<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CarePhoto extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_id',
        'photo_url',
        'thumbnail_url',
        'caption',
        'is_curated',
        'taken_at',
    ];

    protected $casts = [
        'is_curated' => 'boolean',
        'taken_at' => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(CareSession::class, 'session_id');
    }

}