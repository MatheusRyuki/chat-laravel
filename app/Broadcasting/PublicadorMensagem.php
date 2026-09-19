<?php

namespace App\Broadcasting;

use App\Events\MensagemEnviada;
use App\Models\Mensagem;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublicadorMensagem
{
    public function publicar(Mensagem $mensagem): void
    {
        try {
            $this->disparar($mensagem);
        } catch (Throwable $e) {
            Log::warning('Falha ao publicar mensagem no Pusher.', [
                'mensagem_id' => $mensagem->id,
                'tipo' => $e::class,
                'erro' => $e->getMessage(),
            ]);
        }
    }

    protected function disparar(Mensagem $mensagem): void
    {
        $dados = $mensagem->paraBroadcast();

        MensagemEnviada::dispatch(
            $dados['id'],
            $dados['remetente_id'],
            $dados['destinatario_id'],
            $dados['conteudo'],
            $dados['created_at'],
        );
    }
}
