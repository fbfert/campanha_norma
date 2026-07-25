<?php

namespace App\Http\Requests\MessageProcessing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendingSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('message_processing.manage_settings') ?? false;
    }

    public function rules(): array
    {
        return [
            'max_per_minute' => ['required', 'integer', 'min:1', 'lte:max_per_hour'],
            'max_per_hour' => ['required', 'integer', 'min:1', 'lte:max_per_day'],
            'max_per_day' => ['required', 'integer', 'min:1'],
            'minimum_interval_seconds' => ['required', 'integer', 'min:0'],
            'start_time' => ['required', 'date_format:H:i', 'different:end_time'],
            'end_time' => ['required', 'date_format:H:i'],
            'allowed_weekdays' => ['required', 'array', 'min:1'],
            'allowed_weekdays.*' => ['integer', 'between:1,7'],
            'timezone' => ['required', 'timezone'],
            'max_attempts' => ['required', 'integer', 'between:1,10'],
            'retry_interval_minutes' => ['required', 'integer', 'min:1'],
            'retry_backoff_type' => ['required', Rule::in(['fixed', 'linear', 'exponential'])],
            'pause_when_disconnected' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'max_per_minute.lte' => 'O limite por minuto nao pode ser superior ao limite por hora.',
            'max_per_hour.lte' => 'O limite por hora nao pode ser superior ao limite diario.',
            'allowed_weekdays.min' => 'Selecione pelo menos um dia permitido.',
            'start_time.different' => 'O horario inicial deve ser diferente do horario final.',
        ];
    }
}
