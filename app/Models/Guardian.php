<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Guardian extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'relation',
        'contact_address',
        'preferences',
    ];

    protected $casts = [
        'preferences' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function seniors()
    {
        return $this->hasMany(Senior::class);
    }

    public function matchRequests()
    {
        return $this->hasMany(MatchRequest::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function chatbotSessions()
    {
        return $this->hasMany(ChatbotSession::class);
    }

}