<?php

namespace App\Services;

use App\Enums\PapelParticipante;
use App\Enums\TipoConversa;
use App\Models\Conversa;
use App\Models\ConversaParticipante;
use App\Models\Mensagem;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServicoConversa
{
    public function individualEntre(User $um, User $outro): Conversa
    {
        $min = min((int) $um->id, (int) $outro->id);
        $max = max((int) $um->id, (int) $outro->id);
        $chave = $min.':'.$max;

        $existente = Conversa::query()->where('chave_par', $chave)->first();

        if ($existente !== null) {
            return $existente;
        }

        try {
            return DB::transaction(function () use ($chave, $um, $min, $max): Conversa {
                $conversa = Conversa::query()->create([
                    'tipo' => TipoConversa::Individual,
                    'chave_par' => $chave,
                    'criador_id' => $um->id,
                    'versao' => 0,
                ]);

                $conversa->participantes()->createMany([
                    [
                        'user_id' => $min,
                        'papel' => PapelParticipante::Membro,
                    ],
                    [
                        'user_id' => $max,
                        'papel' => PapelParticipante::Membro,
                    ],
                ]);

                return $conversa->fresh(['participantes.user']) ?? $conversa;
            });
        } catch (QueryException) {
            return Conversa::query()->where('chave_par', $chave)->firstOrFail();
        }
    }

    /**
     * @param  Collection<int, User>|array<int, User|int>  $membros
     */
    public function criarGrupo(User $criador, string $nome, Collection|array $membros): Conversa
    {
        $ids = collect($membros)
            ->map(fn ($membro): int => $membro instanceof User ? (int) $membro->id : (int) $membro)
            ->reject(fn (int $id): bool => $id === (int) $criador->id)
            ->unique()
            ->values();

        return DB::transaction(function () use ($criador, $nome, $ids): Conversa {
            $conversa = Conversa::query()->create([
                'tipo' => TipoConversa::Grupo,
                'nome' => $nome,
                'criador_id' => $criador->id,
                'versao' => 0,
            ]);

            $conversa->participantes()->create([
                'user_id' => $criador->id,
                'papel' => PapelParticipante::Criador,
            ]);

            foreach ($ids as $id) {
                $conversa->participantes()->create([
                    'user_id' => $id,
                    'papel' => PapelParticipante::Membro,
                ]);
            }

            return $conversa->fresh(['participantes.user']) ?? $conversa;
        });
    }

    public function adicionarMembro(Conversa $conversa, User $usuario): ConversaParticipante
    {
        $participante = $conversa->participante($usuario);

        if ($participante === null) {
            return $conversa->participantes()->create([
                'user_id' => $usuario->id,
                'papel' => PapelParticipante::Membro,
            ]);
        }

        if ($participante->removido_em !== null) {
            $participante->forceFill([
                'removido_em' => null,
                'papel' => PapelParticipante::Membro,
            ])->save();
        }

        return $participante;
    }

    public function removerMembro(Conversa $conversa, User $usuario): void
    {
        $participante = $conversa->participanteAtivo($usuario);

        if ($participante === null) {
            return;
        }

        $participante->forceFill([
            'removido_em' => now(),
        ])->save();
    }

    /**
     * @return Collection<int, int>
     */
    public function idsAutorizadosParaEvento(Conversa $conversa, ?Mensagem $mensagem = null): Collection
    {
        $ids = $conversa->idsParticipantesAtivos();

        if (! $conversa->eIndividual()) {
            return $ids;
        }

        return $ids->filter(function (int $id) use ($conversa): bool {
            $outro = $conversa->idsParticipantesAtivos()->first(fn (int $candidato): bool => $candidato !== $id);

            if ($outro === null) {
                return true;
            }

            return ! app(ServicoBloqueio::class)->existeEntreIds($id, $outro);
        })->values();
    }
}
