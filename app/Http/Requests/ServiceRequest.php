<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_admin;
    }

    protected function prepareForValidation(): void
    {
        // Aceita "50,00" (padrão brasileiro) e "50.00".
        if (is_string($this->price)) {
            $this->merge(['price' => str_replace(',', '.', trim($this->price))]);
        }
        $this->merge(['active' => $this->boolean('active')]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'price' => ['required', 'regex:/^\d{1,8}(\.\d{1,2})?$/'],
            'active' => ['boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Informe o nome do serviço.',
            'name.max' => 'O nome pode ter no máximo 100 caracteres.',
            'duration_minutes.required' => 'Informe a duração em minutos.',
            'duration_minutes.integer' => 'A duração deve ser um número inteiro de minutos.',
            'duration_minutes.min' => 'A duração deve ser maior que zero.',
            'duration_minutes.max' => 'A duração máxima é de 600 minutos.',
            'price.required' => 'Informe o preço.',
            'price.regex' => 'O preço deve ser um valor positivo ou zero, com até 2 casas decimais (ex.: 50,00).',
        ];
    }
}
