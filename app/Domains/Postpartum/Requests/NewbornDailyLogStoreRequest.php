<?php

namespace App\Domains\Postpartum\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NewbornDailyLogStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'log_datetime'         => ['required', 'date'],
            'log_type'             => ['required', Rule::in([
                'feeding', 'diaper', 'sleep', 'weight', 'jaundice', 'temperature', 'note',
            ])],
            'care_session_id'      => ['nullable', 'integer'],

            // 수유
            'feeding_type'         => ['nullable', Rule::in([
                'breast_left', 'breast_right', 'bottle_breast', 'bottle_formula',
            ])],
            'feeding_volume_ml'    => ['nullable', 'integer', 'min:0', 'max:300'],
            'feeding_duration_min' => ['nullable', 'integer', 'min:0', 'max:120'],

            // 대소변
            'diaper_type'          => ['nullable', Rule::in(['urine', 'stool', 'both'])],
            'stool_color'          => ['nullable', 'string', 'max:20'],

            // 수면
            'sleep_start'          => ['nullable', 'date'],
            'sleep_end'            => ['nullable', 'date', 'after_or_equal:sleep_start'],

            // 체중
            'weight_g'             => ['nullable', 'integer', 'min:500', 'max:10000'],

            // 황달
            'jaundice_level'       => ['nullable', 'integer', 'min:1', 'max:5'],

            // 체온
            'body_temperature'     => ['nullable', 'numeric', 'min:34.0', 'max:42.0'],

            // 메모
            'note_text'            => ['nullable', 'string', 'max:5000'],
            'note_audio_url'       => ['nullable', 'url', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'sleep_end.after_or_equal' => '수면 종료 시간은 수면 시작 시간 이후여야 합니다.',
            'body_temperature.between' => '체온은 34.0 ~ 42.0 사이여야 합니다.',
        ];
    }

    /**
     * 로그 타입별 필수 필드 보충 검증
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $type = $this->input('log_type');
            $rules = match ($type) {
                'feeding'     => ['feeding_type'],
                'diaper'      => ['diaper_type'],
                'sleep'       => ['sleep_start'],
                'weight'      => ['weight_g'],
                'jaundice'    => ['jaundice_level'],
                'temperature' => ['body_temperature'],
                'note'        => ['note_text'],
                default       => [],
            };

            foreach ($rules as $field) {
                if (!$this->filled($field)) {
                    $v->errors()->add($field, "log_type={$type}일 때 {$field}는 필수입니다.");
                }
            }
        });
    }
}
