<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class MensagemEnviada implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public int $id,
        public int $remetenteId,
        public int $destinatarioId,
        public string $conteudo,
        public string $createdAt,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->remetenteId),
            new PrivateChannel('App.Models.User.'.$this->destinatarioId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'mensagem.enviada';
    }

    /**
     * @return array<string, int|string>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->id,
            'remetente_id' => $this->remetenteId,
            'destinatario_id' => $this->destinatarioId,
            'conteudo' => $this->conteudo,
            'created_at' => $this->createdAt,
        ];
    }
}
