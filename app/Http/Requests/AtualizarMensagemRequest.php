<?php

namespace App\Http\Requests;

use App\Models\Mensagem;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AtualizarMensagemRequest extends FormRequest
{
    public function authorize(): bool
    {
        $mensagem = $this->route('mensagem');

        return $mensagem instanceof Mensagem
            && $this->user()?->can('update', $mensagem);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'conteudo' => ['required', 'string', 'max:'.Mensagem::TAMANHO_MAXIMO],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
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
