<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class OtpSendRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^01[0-9]\d{7,8}$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => '휴대폰 번호를 입력해주세요.',
            'phone.regex' => '올바른 휴대폰 번호 형식이 아닙니다. (예: 01012345678)',
        ];
    }
}
