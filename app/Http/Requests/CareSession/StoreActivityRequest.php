<?php

namespace App\Http\Requests\CareSession;

use Illuminate\Foundation\Http\FormRequest;

class StoreActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && $this->user()->caregiver !== null;
    }

    public function rules(): array
    {
        return [
            'category' => ['required', 'in:meal,medication,exercise,bath,mood,cognition,other,cleaning,repair,organizing,nursing_care,position_change'],
            'data' => ['required', 'array'],
            'memo' => ['nullable', 'string', 'max:500'],
        ];
    }
}
