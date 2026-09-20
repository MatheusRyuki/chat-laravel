<?php

namespace App\Console\Commands;

use App\Events\DiagnosticoPusher;
use App\Models\User;
use Illuminate\Console\Command;

class ChatDiagnosticoPusher extends Command
{
    protected $signature = 'chat:diagnostico-pusher {usuario : ID ou e-mail do usuário}';

    protected $description = 'Dispara um evento técnico fictício no canal privado do usuário para validar o Pusher';

    public function handle(): int
    {
        $identificador = $this->argument('usuario');

        $usuario = is_numeric($identificador)
            ? User::query()->find($identificador)
            : User::query()->where('email', $identificador)->first();

        if (! $usuario) {
            $this->error('Usuário não encontrado.');

            return self::FAILURE;
        }

        $conexao = config('broadcasting.connections.pusher');
        $chave = (string) data_get($conexao, 'key');
        $segredo = (string) data_get($conexao, 'secret');
        $appId = (string) data_get($conexao, 'app_id');
        $cluster = (string) data_get($conexao, 'options.cluster');

        if ($chave === '' || $segredo === '' || $appId === '' || $cluster === '') {
            $this->error('Pusher não configurado. Preencha PUSHER_APP_ID, PUSHER_APP_KEY, PUSHER_APP_SECRET e PUSHER_APP_CLUSTER no .env.');

            return self::FAILURE;
        }

        DiagnosticoPusher::dispatch($usuario, 'Evento técnico fictício.');

        $this->info("Evento disparado no canal privado App.Models.User.{$usuario->id}.");

        return self::SUCCESS;
    }
}
