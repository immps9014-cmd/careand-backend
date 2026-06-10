<?php

namespace App\Domains\Postpartum\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PostpartumClientUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'breastfeeding_intent'  => ['sometimes', Rule::in(['exclusive', 'mixed', 'formula', 'undecided'])],
            'postpartum_conditions' => ['sometimes', 'nullable', 'array'],
            'medications'           => ['sometimes', 'nullable', 'array'],
            'special_notes'         => ['sometimes', 'nullable', 'string'],
            'status'                => ['sometimes', Rule::in(['active', 'completed', 'cancelled'])],
        ];
    }
}
