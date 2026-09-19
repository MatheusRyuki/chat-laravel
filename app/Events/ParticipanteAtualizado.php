<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class ParticipanteAtualizado implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  array<int, int>  $destinatarioIds
     */
    public function __construct(
        public int $conversaId,
        public int $usuarioId,
        public string $acao,
        public string $nomeGrupo,
        public array $destinatarioIds,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return collect($this->destinatarioIds)
            ->unique()
            ->map(fn (int $id): PrivateChannel => new PrivateChannel(canal_privado_usuario($id)))
            ->values()
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'participante.atualizado';
    }

    /**
     * @return array<string, int|string>
     */
    public function broadcastWith(): array
    {
        return [
            'conversa_id' => $this->conversaId,
            'usuario_id' => $this->usuarioId,
            'acao' => $this->acao,
            'nome_grupo' => $this->nomeGrupo,
        ];
    }
}
