<?php

namespace App\Http\Requests\CareSession;

use Illuminate\Foundation\Http\FormRequest;

class UploadVoiceLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->caregiver !== null;
    }

    public function rules(): array
    {
        return [
            'audio_url' => ['required', 'url', 'max:500'],
            'duration_sec' => ['required', 'integer', 'between:1,1800'], // 1초 ~ 30분
        ];
    }

    public function messages(): array
    {
        return [
            'duration_sec.between' => '음성은 1초 ~ 30분 이내여야 합니다.',
        ];
    }
}
