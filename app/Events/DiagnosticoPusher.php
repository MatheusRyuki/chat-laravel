<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DiagnosticoPusher implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public User $usuario,
        public string $mensagem,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('App.Models.User.'.$this->usuario->id);
    }

    public function broadcastAs(): string
    {
        return 'diagnostico.pusher';
    }

    public function broadcastWith(): array
    {
        return [
            'mensagem' => $this->mensagem,
            'usuario_id' => $this->usuario->id,
        ];
    }
}
