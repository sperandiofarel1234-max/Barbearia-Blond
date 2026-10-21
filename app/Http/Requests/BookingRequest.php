<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->customer_phone)) {
            $this->merge(['customer_phone' => preg_replace('/\D+/', '', $this->customer_phone)]);
        }
        if (is_string($this->customer_name)) {
            $this->merge(['customer_name' => trim($this->customer_name)]);
        }
        if (is_string($this->customer_email)) {
            $this->merge(['customer_email' => mb_strtolower(trim($this->customer_email))]);
        }
    }

    /** Somente estes campos são aceitos do navegador; preço, duração, horário final e status nunca. */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'customer_name' => ['required', 'string', 'min:2', 'max:120'],
            'customer_phone' => ['required', 'regex:/^\d{10,11}$/'],
            'customer_email' => ['required', 'email:rfc', 'max:190'],
        ];
    }

    public function messages(): array
    {
        return [
            'date.required' => 'Escolha uma data.',
            'date.date_format' => 'Data inválida.',
            'time.required' => 'Escolha um horário.',
            'time.date_format' => 'Horário inválido.',
            'customer_name.required' => 'Informe seu nome.',
            'customer_name.min' => 'Informe seu nome completo.',
            'customer_phone.required' => 'Informe seu telefone.',
            'customer_phone.regex' => 'Informe um telefone válido com DDD (10 ou 11 dígitos).',
            'customer_email.required' => 'Informe seu e-mail (usado para os lembretes).',
            'customer_email.email' => 'Informe um e-mail válido.',
        ];
    }
}
