<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegistrarLeituraRequest extends FormRequest
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
            'conversa_id' => ['required', 'integer', Rule::exists('conversas', 'id')],
            'ate_id' => ['required', 'integer', Rule::exists('mensagens', 'id')],
        ];
    }
}
