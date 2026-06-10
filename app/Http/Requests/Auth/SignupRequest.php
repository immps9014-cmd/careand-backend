<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class SignupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'phone' => ['required', 'string', 'regex:/^01[0-9]\d{7,8}$/', 'unique:users,phone'],
            'phone_verify_token' => ['required', 'string'],
            'name' => ['required', 'string', 'max:50'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'role' => ['required', 'in:guardian,caregiver,organization'],
            'relation' => ['required_if:role,guardian', 'string', 'max:20'],
            'agree_terms' => ['required', 'accepted'],
            'agree_privacy' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => '이미 가입된 이메일입니다.',
            'phone.unique' => '이미 가입된 휴대폰 번호입니다.',
            'password.confirmed' => '비밀번호가 일치하지 않습니다.',
            'agree_terms.accepted' => '이용약관 동의가 필요합니다.',
            'agree_privacy.accepted' => '개인정보 처리방침 동의가 필요합니다.',
        ];
    }
}
