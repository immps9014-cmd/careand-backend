<?php

namespace App\Domains\Housekeeping\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ServiceAddressUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->guardian !== null;
    }

    public function rules(): array
    {
        return [
            'label' => ['sometimes', 'string', 'max:50'],
            'address' => ['sometimes', 'string', 'max:255'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'dwelling_type' => ['sometimes', 'in:apartment,villa,house,officetel,other'],
            'size_m2' => ['nullable', 'integer', 'between:1,3000'],
            'has_pets' => ['nullable', 'boolean'],
            'entry_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
