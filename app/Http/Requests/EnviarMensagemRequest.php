<?php

namespace App\Http\Requests;

use App\Models\Conversa;
use App\Models\Mensagem;
use App\Models\User;
use App\Services\ServicoConversa;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
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
                'nullable',
                'integer',
                Rule::exists('users', 'id'),
                Rule::notIn([(int) $this->user()->id]),
            ],
            'conversa_id' => [
                'nullable',
                'integer',
                Rule::exists('conversas', 'id'),
            ],
            'conteudo' => ['nullable', 'string', 'max:'.Mensagem::TAMANHO_MAXIMO],
            'anexo' => [
                'nullable',
                'file',
                'max:'.config('chat.anexo_maximo_kb'),
                'mimes:jpeg,jpg,png',
                'mimetypes:image/jpeg,image/png',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'destinatario_id.exists' => 'O destinatário informado não existe.',
            'destinatario_id.not_in' => 'Não é possível enviar uma mensagem para você mesmo.',
            'conteudo.max' => 'A mensagem deve ter no máximo '.Mensagem::TAMANHO_MAXIMO.' caracteres.',
            'anexo.max' => 'A imagem deve ter no máximo 2 MB.',
            'anexo.mimes' => 'Envie apenas imagens JPEG ou PNG.',
            'anexo.mimetypes' => 'Envie apenas imagens JPEG ou PNG.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->filled('destinatario_id') && ! $this->filled('conversa_id')) {
                $validator->errors()->add('destinatario_id', 'Selecione um contato para enviar a mensagem.');
            }

            $texto = trim((string) $this->input('conteudo'));

            if ($texto === '' && ! $this->file('anexo')) {
                $validator->errors()->add('conteudo', 'A mensagem não pode estar vazia.');
            }

            if ($this->file('anexo') && $this->filled('conversa_id')) {
                $conversa = Conversa::query()->find((int) $this->input('conversa_id'));

                if ($conversa?->eGrupo()) {
                    $validator->errors()->add('anexo', 'Anexos de imagem estão disponíveis apenas em conversas individuais.');
                }
            }
        });
    }

    public function conversa(): Conversa
    {
        if ($this->filled('conversa_id')) {
            return Conversa::query()->findOrFail((int) $this->input('conversa_id'));
        }

        $destinatario = User::query()->findOrFail((int) $this->input('destinatario_id'));

        return app(ServicoConversa::class)->individualEntre($this->user(), $destinatario);
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
