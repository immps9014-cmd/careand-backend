<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
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
            // 로그인 식별자(아이디). email 컬럼을 재사용하되 이메일 형식 강제 없이 영문/숫자 아이디 허용.
            // (기존 이메일형 계정도 통과하도록 @.+-_ 등 허용) 4~120자, 영문/숫자로 시작.
            'email' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9._@+\-]{3,119}$/', 'unique:users,email'],
            'phone' => ['required', 'string', 'regex:/^01[0-9]\d{7,8}$/', 'unique:users,phone'],
            'phone_verify_token' => ['required', 'string'],
            'name' => ['required', 'string', 'max:50'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
            'role' => ['required', 'in:guardian,caregiver,organization'],
            // 가입 의도(보호자/가사요청자/산모요청자). guardian일 때만 의미. 미지정 시 care.
            'intent' => ['nullable', 'in:care,housekeeping,postpartum'],
            // 어르신과의 관계는 '보호자(care)' 가입에만 필수. 가사(housekeeping)·산모(postpartum) 본인 요청은 불필요.
            'relation' => [
                Rule::requiredIf(fn () => $this->input('role') === 'guardian' && ! in_array($this->input('intent'), ['housekeeping', 'postpartum'], true)),
                'nullable',
                'string',
                'max:20',
            ],
            'agree_terms' => ['required', 'accepted'],
            'agree_privacy' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => '이미 사용 중인 아이디입니다.',
            'email.regex' => '아이디는 영문/숫자로 시작하는 4자 이상이어야 합니다.',
            'phone.unique' => '이미 가입된 휴대폰 번호입니다.',
            'password.confirmed' => '비밀번호가 일치하지 않습니다.',
            'agree_terms.accepted' => '이용약관 동의가 필요합니다.',
            'agree_privacy.accepted' => '개인정보 처리방침 동의가 필요합니다.',
        ];
    }
}
