<?php

namespace App\Models;

use Database\Factories\MensagemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['remetente_id', 'destinatario_id', 'conteudo'])]
class Mensagem extends Model
{
    /** @use HasFactory<MensagemFactory> */
    use HasFactory;

    public const TAMANHO_MAXIMO = 1000;

    protected $table = 'mensagens';

    /**
     * @param  Builder<Mensagem>  $query
     */
    public function scopeEntre(Builder $query, User $um, User $outro): void
    {
        $query->where(function (Builder $conversa) use ($um, $outro) {
            $conversa->where(function (Builder $ida) use ($um, $outro) {
                $ida->where('remetente_id', $um->id)
                    ->where('destinatario_id', $outro->id);
            })->orWhere(function (Builder $volta) use ($um, $outro) {
                $volta->where('remetente_id', $outro->id)
                    ->where('destinatario_id', $um->id);
            });
        });
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

    /**
     * @return array{id: int, remetente_id: int, destinatario_id: int, conteudo: string, created_at: string}
     */
    public function paraBroadcast(): array
    {
        return [
            'id' => (int) $this->id,
            'remetente_id' => (int) $this->remetente_id,
            'destinatario_id' => (int) $this->destinatario_id,
            'conteudo' => (string) $this->conteudo,
            'created_at' => $this->created_at?->toIso8601String() ?? '',
        ];
    }
}
