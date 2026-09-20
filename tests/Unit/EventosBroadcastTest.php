<?php

namespace Tests\Unit;

use App\Events\DiagnosticoPusher;
use App\Events\MensagemAlterada;
use App\Events\MensagemEnviada;
use App\Events\ParticipanteAtualizado;
use App\Events\ParticipanteDigitando;
use App\Models\User;
use Tests\TestCase;

class EventosBroadcastTest extends TestCase
{
    public function test_mensagens_preservam_payload_e_nao_duplicam_canais(): void
    {
        config(['chat.prefixo_canal' => 'teste-']);
        $payload = ['id' => 4, 'conteudo' => 'Olá', 'versao' => 2];
        foreach ([new MensagemEnviada($payload, [2, 3, 2]), new MensagemAlterada($payload, [2, 3, 2])] as $evento) {
            $this->assertSame(['private-teste-App.Models.User.2', 'private-teste-App.Models.User.3'], array_map(fn ($canal) => $canal->name, $evento->broadcastOn()));
            $this->assertSame($payload, $evento->broadcastWith());
        }
        $this->assertSame('mensagem.enviada', (new MensagemEnviada([], []))->broadcastAs());
        $this->assertSame('mensagem.alterada', (new MensagemAlterada([], []))->broadcastAs());
        $this->assertSame([], (new MensagemAlterada([], []))->broadcastOn());
        $this->assertSame('teste-presenca.chat', canal_presenca_chat());
    }

    public function test_participante_e_digitacao_expoem_apenas_o_contrato_publico(): void
    {
        $membro = new ParticipanteAtualizado(8, 2, 'removido', 'Equipe', [2, 3, 3]);
        $this->assertSame('participante.atualizado', $membro->broadcastAs());
        $this->assertSame(['conversa_id' => 8, 'usuario_id' => 2, 'acao' => 'removido', 'nome_grupo' => 'Equipe'], $membro->broadcastWith());
        $this->assertCount(2, $membro->broadcastOn());
        $digitacao = new ParticipanteDigitando(8, 2, 'Ana', false, [2, 3, 3]);
        $this->assertSame('participante.digitando', $digitacao->broadcastAs());
        $this->assertFalse($digitacao->broadcastWith()['digitando']);
        $this->assertArrayNotHasKey('conteudo', $digitacao->broadcastWith());
        $this->assertSame(['private-App.Models.User.3'], array_map(fn ($canal) => $canal->name, $digitacao->broadcastOn()));
    }

    public function test_diagnostico_identifica_usuario_e_evento(): void
    {
        $usuario = new User;
        $usuario->id = 12;
        $evento = new DiagnosticoPusher($usuario, 'Teste');
        $this->assertSame('private-App.Models.User.12', $evento->broadcastOn()->name);
        $this->assertSame('diagnostico.pusher', $evento->broadcastAs());
        $this->assertSame(['mensagem' => 'Teste', 'usuario_id' => 12], $evento->broadcastWith());
    }
}
