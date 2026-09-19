<?php

namespace App\Policies;

use App\Models\Mensagem;
use App\Models\User;
use App\Services\ServicoBloqueio;

class MensagemPolicy
{
    public function view(User $user, Mensagem $mensagem): bool
    {
        $conversa = $mensagem->conversa;

        return $conversa !== null && $conversa->usuarioParticipa($user);
    }

    public function update(User $user, Mensagem $mensagem): bool
    {
        if ($mensagem->foiRemovida()) {
            return false;
        }

        if ((int) $mensagem->remetente_id !== (int) $user->id) {
            return false;
        }

        $conversa = $mensagem->conversa;

        if ($conversa === null || ! $conversa->usuarioParticipa($user)) {
            return false;
        }

        if ($conversa->eIndividual() && app(ServicoBloqueio::class)->conversaIndividualBloqueada($conversa, $user)) {
            return false;
        }

        return true;
    }

    public function delete(User $user, Mensagem $mensagem): bool
    {
        return $this->update($user, $mensagem);
    }

    public function verAnexo(User $user, Mensagem $mensagem): bool
    {
        if ($mensagem->foiRemovida() || ! $mensagem->temAnexo()) {
            return false;
        }

        return $this->view($user, $mensagem);
    }
}
