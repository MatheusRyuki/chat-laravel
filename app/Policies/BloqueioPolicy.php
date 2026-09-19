<?php

namespace App\Policies;

use App\Models\Bloqueio;
use App\Models\User;

class BloqueioPolicy
{
    public function delete(User $user, Bloqueio $bloqueio): bool
    {
        return (int) $bloqueio->bloqueador_id === (int) $user->id;
    }
}
