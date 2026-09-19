<?php

namespace App\Models;

use App\Enums\PapelParticipante;
use App\Enums\TipoConversa;
use Database\Factories\ConversaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

#[Fillable(['tipo', 'nome', 'chave_par', 'criador_id', 'versao', 'ultima_mensagem_id', 'ultima_mensagem_em'])]
class Conversa extends Model
{
    /** @use HasFactory<ConversaFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoConversa::class,
            'versao' => 'integer',
            'ultima_mensagem_em' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ConversaParticipante, $this>
     */
    public function participantes(): HasMany
    {
        return $this->hasMany(ConversaParticipante::class);
    }

    /**
     * @return HasMany<ConversaParticipante, $this>
     */
    public function participantesAtivos(): HasMany
    {
        return $this->participantes()->whereNull('removido_em');
    }

    /**
     * @return HasMany<Mensagem, $this>
     */
    public function mensagens(): HasMany
    {
        return $this->hasMany(Mensagem::class);
    }

    /**
     * @return BelongsTo<Mensagem, $this>
     */
    public function ultimaMensagem(): BelongsTo
    {
        return $this->belongsTo(Mensagem::class, 'ultima_mensagem_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function criador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criador_id');
    }

    public function eIndividual(): bool
    {
        return $this->tipo === TipoConversa::Individual;
    }

    public function eGrupo(): bool
    {
        return $this->tipo === TipoConversa::Grupo;
    }

    public function participante(User $usuario): ?ConversaParticipante
    {
        if ($this->relationLoaded('participantes')) {
            return $this->participantes->firstWhere('user_id', $usuario->id);
        }

        return $this->participantes()->where('user_id', $usuario->id)->first();
    }

    public function participanteAtivo(User $usuario): ?ConversaParticipante
    {
        $participante = $this->participante($usuario);

        if ($participante === null || $participante->removido_em !== null) {
            return null;
        }

        return $participante;
    }

    public function usuarioParticipa(User $usuario): bool
    {
        return $this->participanteAtivo($usuario) !== null;
    }

    public function usuarioECriador(User $usuario): bool
    {
        $participante = $this->participanteAtivo($usuario);

        return $participante !== null && $participante->papel === PapelParticipante::Criador;
    }

    public function outroParticipante(User $usuario): ?User
    {
        if (! $this->eIndividual()) {
            return null;
        }

        $outro = $this->participantesAtivos
            ->first(fn (ConversaParticipante $participante): bool => $participante->user_id !== $usuario->id);

        return $outro?->user;
    }

    /**
     * @return Collection<int, int>
     */
    public function idsParticipantesAtivos(): Collection
    {
        if ($this->relationLoaded('participantesAtivos')) {
            return $this->participantesAtivos->pluck('user_id')->map(fn ($id): int => (int) $id)->values();
        }

        return $this->participantesAtivos()->pluck('user_id')->map(fn ($id): int => (int) $id)->values();
    }

    public function proximaVersao(): int
    {
        $this->increment('versao');

        return (int) $this->versao;
    }

    public function registrarEnvio(Mensagem $mensagem): void
    {
        $this->forceFill([
            'ultima_mensagem_id' => $mensagem->id,
            'ultima_mensagem_em' => $mensagem->created_at,
            'versao' => max((int) $this->versao, (int) $mensagem->versao),
        ])->save();
    }
}
