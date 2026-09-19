<?php

namespace App\Services;

use App\Models\Bloqueio;
use App\Models\Conversa;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class ServicoBloqueio
{
    public function existeEntre(User $um, User $outro): bool
    {
        return $this->existeEntreIds((int) $um->id, (int) $outro->id);
    }

    public function existeEntreIds(int $um, int $outro): bool
    {
        return Bloqueio::query()
            ->where(function ($consulta) use ($um, $outro) {
                $consulta->where('bloqueador_id', $um)->where('bloqueado_id', $outro);
            })
            ->orWhere(function ($consulta) use ($um, $outro) {
                $consulta->where('bloqueador_id', $outro)->where('bloqueado_id', $um);
            })
            ->exists();
    }

    public function bloqueioDe(User $bloqueador, User $bloqueado): ?Bloqueio
    {
        return Bloqueio::query()
            ->where('bloqueador_id', $bloqueador->id)
            ->where('bloqueado_id', $bloqueado->id)
            ->first();
    }

    public function bloquear(User $bloqueador, User $bloqueado): Bloqueio
    {
        return Bloqueio::query()->firstOrCreate([
            'bloqueador_id' => $bloqueador->id,
            'bloqueado_id' => $bloqueado->id,
        ]);
    }

    public function desbloquear(User $bloqueador, User $bloqueado): void
    {
        Bloqueio::query()
            ->where('bloqueador_id', $bloqueador->id)
            ->where('bloqueado_id', $bloqueado->id)
            ->delete();
    }

    /**
     * @return Collection<int, Bloqueio>
     */
    public function feitosPor(User $usuario): Collection
    {
        return Bloqueio::query()
            ->with('bloqueado')
            ->where('bloqueador_id', $usuario->id)
            ->orderBy('created_at')
            ->get();
    }

    public function conversaIndividualBloqueada(Conversa $conversa, User $usuario): bool
    {
        if (! $conversa->eIndividual()) {
            return false;
        }

        $outro = $conversa->outroParticipante($usuario);

        if ($outro === null) {
            return false;
        }

        return $this->existeEntre($usuario, $outro);
    }
}
