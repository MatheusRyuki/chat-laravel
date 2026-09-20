<?php

namespace App\Services;

use App\Models\Conversa;
use App\Models\Mensagem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ServicoLeitura
{
    public function registrar(User $usuario, Conversa $conversa, int $ateId): void
    {
        $participante = $conversa->participanteAtivo($usuario);

        if ($participante === null) {
            abort(403);
        }

        $mensagem = Mensagem::query()
            ->where('conversa_id', $conversa->id)
            ->whereKey($ateId)
            ->first();

        if ($mensagem === null) {
            abort(404);
        }

        $participante->registrarLeitura($ateId);
    }

    /**
     * @param  array<int, int>  $conversaIds
     * @return array<int, int>
     */
    public function contarNaoLidas(User $usuario, array $conversaIds): array
    {
        if ($conversaIds === []) {
            return [];
        }

        return Mensagem::query()
            ->join('conversa_participantes as p', function ($join) use ($usuario): void {
                $join->on('p.conversa_id', '=', 'mensagens.conversa_id')
                    ->where('p.user_id', $usuario->id)
                    ->whereNull('p.removido_em');
            })
            ->whereIn('mensagens.conversa_id', $conversaIds)
            ->whereNull('mensagens.removida_em')
            ->where('mensagens.remetente_id', '!=', $usuario->id)
            ->whereRaw('mensagens.id > COALESCE(p.ultima_leitura_mensagem_id, 0)')
            ->groupBy('mensagens.conversa_id')
            ->select('mensagens.conversa_id', DB::raw('COUNT(*) as total'))
            ->get()
            ->mapWithKeys(fn ($linha): array => [(int) $linha->conversa_id => (int) $linha->total])
            ->all();
    }

    public function quantidade(User $usuario, Conversa $conversa): int
    {
        $participante = $conversa->participanteAtivo($usuario);

        if ($participante === null) {
            return 0;
        }

        return Mensagem::query()
            ->where('conversa_id', $conversa->id)
            ->whereNull('removida_em')
            ->where('remetente_id', '!=', $usuario->id)
            ->where('id', '>', (int) ($participante->ultima_leitura_mensagem_id ?? 0))
            ->count();
    }
}
