<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return HasMany<Mensagem, $this>
     */
    public function mensagensEnviadas(): HasMany
    {
        return $this->hasMany(Mensagem::class, 'remetente_id');
    }

    /**
     * @return HasMany<Mensagem, $this>
     */
    public function mensagensRecebidas(): HasMany
    {
        return $this->hasMany(Mensagem::class, 'destinatario_id');
    }

    /**
     * @return HasMany<ConversaParticipante, $this>
     */
    public function participacoes(): HasMany
    {
        return $this->hasMany(ConversaParticipante::class);
    }

    /**
     * @return BelongsToMany<Conversa, $this>
     */
    public function conversas(): BelongsToMany
    {
        return $this->belongsToMany(Conversa::class, 'conversa_participantes')
            ->withPivot(['papel', 'ultima_leitura_mensagem_id', 'removido_em'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Bloqueio, $this>
     */
    public function bloqueiosFeitos(): HasMany
    {
        return $this->hasMany(Bloqueio::class, 'bloqueador_id');
    }

    /**
     * @return HasMany<Bloqueio, $this>
     */
    public function bloqueiosRecebidos(): HasMany
    {
        return $this->hasMany(Bloqueio::class, 'bloqueado_id');
    }
}
