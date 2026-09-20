<?php

namespace App\Models;

use Database\Factories\MensagemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'conversa_id',
    'remetente_id',
    'destinatario_id',
    'conteudo',
    'versao',
    'editada_em',
    'removida_em',
    'anexo_caminho',
    'anexo_mime',
    'anexo_tamanho',
])]
class Mensagem extends Model
{
    /** @use HasFactory<MensagemFactory> */
    use HasFactory;

    public const TAMANHO_MAXIMO = 1000;

    protected $table = 'mensagens';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'versao' => 'integer',
            'editada_em' => 'datetime',
            'removida_em' => 'datetime',
            'anexo_tamanho' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Conversa, $this>
     */
    public function conversa(): BelongsTo
    {
        return $this->belongsTo(Conversa::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function remetente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'remetente_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function destinatario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'destinatario_id');
    }

    public function foiRemovida(): bool
    {
        return $this->removida_em !== null;
    }

    public function foiEditada(): bool
    {
        return $this->editada_em !== null && ! $this->foiRemovida();
    }

    public function temAnexo(): bool
    {
        return filled($this->anexo_caminho) && ! $this->foiRemovida();
    }

    public function urlAnexo(): ?string
    {
        if (! $this->temAnexo()) {
            return null;
        }

        return route('mensagens.anexo', $this);
    }

    /**
     * @return array<string, mixed>
     */
    public function paraBroadcast(): array
    {
        return [
            'id' => (int) $this->id,
            'conversa_id' => (int) $this->conversa_id,
            'remetente_id' => (int) $this->remetente_id,
            'destinatario_id' => $this->destinatario_id !== null ? (int) $this->destinatario_id : null,
            'remetente_nome' => $this->remetente?->name,
            'conteudo' => $this->foiRemovida() ? '' : (string) $this->conteudo,
            'versao' => (int) $this->versao,
            'editada' => $this->foiEditada(),
            'removida' => $this->foiRemovida(),
            'anexo_url' => $this->urlAnexo(),
            'anexo_mime' => $this->temAnexo() ? $this->anexo_mime : null,
            'created_at' => $this->created_at?->toIso8601String() ?? '',
            'editada_em' => $this->editada_em?->toIso8601String(),
        ];
    }
}
