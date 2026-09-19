<?php

namespace App\Models;

use App\Enums\PapelParticipante;
use Database\Factories\ConversaParticipanteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['conversa_id', 'user_id', 'papel', 'ultima_leitura_mensagem_id', 'removido_em'])]
class ConversaParticipante extends Model
{
    /** @use HasFactory<ConversaParticipanteFactory> */
    use HasFactory;

    protected $table = 'conversa_participantes';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'papel' => PapelParticipante::class,
            'ultima_leitura_mensagem_id' => 'integer',
            'removido_em' => 'datetime',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function registrarLeitura(int $mensagemId): bool
    {
        $atual = (int) ($this->ultima_leitura_mensagem_id ?? 0);

        if ($mensagemId <= $atual) {
            return false;
        }

        $this->forceFill([
            'ultima_leitura_mensagem_id' => $mensagemId,
        ])->save();

        return true;
    }
}
