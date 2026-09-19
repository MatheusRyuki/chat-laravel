<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class MensagemAlterada implements ShouldBroadcastNow
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, int>  $destinatarioIds
     */
    public function __construct(
        public array $payload,
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
        return 'mensagem.alterada';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
