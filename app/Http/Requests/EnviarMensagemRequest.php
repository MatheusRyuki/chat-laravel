<?php

namespace App\Http\Requests;

use App\Models\Mensagem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnviarMensagemRequest extends FormRequest
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
            'destinatario_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id'),
                Rule::notIn([(int) $this->user()->id]),
            ],
            'conteudo' => ['required', 'string', 'max:'.Mensagem::TAMANHO_MAXIMO],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'destinatario_id.required' => 'Selecione um contato para enviar a mensagem.',
            'destinatario_id.exists' => 'O destinatário informado não existe.',
            'destinatario_id.not_in' => 'Não é possível enviar uma mensagem para você mesmo.',
            'conteudo.required' => 'A mensagem não pode estar vazia.',
            'conteudo.max' => 'A mensagem deve ter no máximo '.Mensagem::TAMANHO_MAXIMO.' caracteres.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('conteudo')) {
            $this->merge([
                'conteudo' => trim((string) $this->input('conteudo')),
            ]);
        }
    }
}
