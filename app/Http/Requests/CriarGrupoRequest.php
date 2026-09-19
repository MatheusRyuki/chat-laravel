<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CriarGrupoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:80'],
            'membros' => ['required', 'array', 'min:1'],
            'membros.*' => [
                'integer',
                Rule::exists('users', 'id'),
                Rule::notIn([(int) $this->user()->id]),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nome.required' => 'Informe o nome do grupo.',
            'membros.required' => 'Selecione pelo menos um participante.',
            'membros.min' => 'Selecione pelo menos um participante.',
        ];
    }
}
