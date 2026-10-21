<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RescheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.required' => 'Escolha a nova data.',
            'date.date_format' => 'Data inválida.',
            'time.required' => 'Escolha o novo horário.',
            'time.date_format' => 'Horário inválido.',
        ];
    }
}
