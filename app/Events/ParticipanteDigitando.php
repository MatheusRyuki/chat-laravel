<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class ParticipanteDigitando implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  array<int, int>  $destinatarioIds
     */
    public function __construct(
        public int $conversaId,
        public int $usuarioId,
        public string $nome,
        public bool $digitando,
        public array $destinatarioIds,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return collect($this->destinatarioIds)
            ->unique()
            ->reject(fn (int $id): bool => $id === $this->usuarioId)
            ->map(fn (int $id): PrivateChannel => new PrivateChannel(canal_privado_usuario($id)))
            ->values()
            ->all();
    }

    public function broadcastAs(): string
    {
        return 'participante.digitando';
    }

    /**
     * @return array<string, int|string|bool>
     */
    public function broadcastWith(): array
    {
        return [
            'conversa_id' => $this->conversaId,
            'usuario_id' => $this->usuarioId,
            'nome' => $this->nome,
            'digitando' => $this->digitando,
        ];
    }
}
