<?php

namespace App\Policies;

use App\Models\Conversa;
use App\Models\User;
use App\Services\ServicoBloqueio;

class ConversaPolicy
{
    public function view(User $user, Conversa $conversa): bool
    {
        return $conversa->usuarioParticipa($user);
    }

    public function enviar(User $user, Conversa $conversa): bool
    {
        if (! $conversa->usuarioParticipa($user)) {
            return false;
        }

        if ($conversa->eIndividual() && app(ServicoBloqueio::class)->conversaIndividualBloqueada($conversa, $user)) {
            return false;
        }

        return true;
    }

    public function gerenciarMembros(User $user, Conversa $conversa): bool
    {
        return $conversa->eGrupo() && $conversa->usuarioECriador($user);
    }

    public function digitar(User $user, Conversa $conversa): bool
    {
        return $this->enviar($user, $conversa);
    }
}
