<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** 산모신생아 바우처 전자서명 서류 한 건(CAREN-MNH-01 3단계) */
class MnhDocument extends Model
{
    protected $fillable = [
        'doc_type', 'contract_id', 'caregiver_id', 'care_session_id', 'template_id', 'template_version', 'title',
        'content_html', 'form_data', 'status', 'signer_role', 'signer_user_id', 'signer_name', 'signature_path',
        'signed_at', 'signed_ip', 'signed_ua', 'captured_by', 'content_hash', 'pdf_path', 'pdf_generated_at',
        'issued_by', 'void_reason',
    ];

    protected $casts = [
        'form_data' => 'array',
        'signed_at' => 'datetime',
        'pdf_generated_at' => 'datetime',
    ];
}
