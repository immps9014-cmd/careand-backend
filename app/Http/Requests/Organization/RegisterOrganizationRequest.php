<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class RegisterOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->role === 'organization';
    }

    public function rules(): array
    {
        return [
            'biz_no' => ['required', 'string', 'max:20', 'unique:organizations,biz_no'],
            'name' => ['required', 'string', 'max:100'],
            'representative' => ['required', 'string', 'max:50'],
            'contact_phone' => ['required', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'],
            'biz_type' => ['nullable', 'string', 'max:50'],
            'certifications' => ['nullable', 'array'],
            'certifications.*' => ['string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'biz_no.required' => '사업자등록번호를 입력해주세요.',
            'biz_no.unique' => '이미 등록된 사업자등록번호입니다.',
            'name.required' => '기관명을 입력해주세요.',
            'representative.required' => '대표자명을 입력해주세요.',
            'contact_phone.required' => '기관 연락처를 입력해주세요.',
        ];
    }
}
