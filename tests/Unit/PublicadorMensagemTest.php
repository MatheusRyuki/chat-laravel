<?php

namespace Tests\Unit;

use App\Broadcasting\PublicadorMensagem;
use App\Events\MensagemAlterada;
use App\Events\ParticipanteAtualizado;
use App\Events\ParticipanteDigitando;
use App\Models\Conversa;
use App\Models\Mensagem;
use App\Models\User;
use App\Services\ServicoBloqueio;
use App\Services\ServicoConversa;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicadorMensagemTest extends TestCase
{
    public static function publicacoes(): array
    {
        return [['alteracao'], ['digitacao'], ['participante']];
    }

    #[DataProvider('publicacoes')]
    public function test_falha_no_transporte_e_registrada_sem_propagar(string $tipo): void
    {
        $conversas = Mockery::mock(ServicoConversa::class);
        $conversas->shouldReceive('idsAutorizadosParaEvento')->andReturn(collect([2]));
        $bloqueios = Mockery::mock(ServicoBloqueio::class);
        $bloqueios->shouldReceive('conversaIndividualBloqueada')->andReturn(false);
        $publicador = new PublicadorMensagem($conversas, $bloqueios);
        $grupo = new Conversa(['nome' => 'Equipe']);
        $grupo->id = 5;
        $usuario = new User(['name' => 'Ana']);
        $usuario->id = 2;
        Event::shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('transporte indisponível'));
        Log::shouldReceive('warning')->once()->with(Mockery::type('string'), Mockery::on(fn ($dados) => $dados['erro'] === 'transporte indisponível'));
        if ($tipo === 'alteracao') {
            $mensagem = new Mensagem(['conteudo' => 'Olá']);
            $mensagem->setRelation('conversa', $grupo)->setRelation('remetente', $usuario);
            $publicador->publicarAlteracao($mensagem);
        } elseif ($tipo === 'digitacao') {
            $publicador->publicarDigitacao($grupo, $usuario, true);
        } else {
            $publicador->publicarParticipante($grupo, $usuario, 'adicionado', [2]);
        }
    }

    public function test_digitacao_bloqueada_ou_sem_destinatario_nao_publica(): void
    {
        Event::fake([ParticipanteDigitando::class]);
        $conversas = Mockery::mock(ServicoConversa::class);
        $conversas->shouldReceive('idsAutorizadosParaEvento')->once()->andReturn(collect());
        $bloqueios = Mockery::mock(ServicoBloqueio::class);
        $bloqueios->shouldReceive('conversaIndividualBloqueada')->twice()->andReturn(true, false);
        $publicador = new PublicadorMensagem($conversas, $bloqueios);
        $publicador->publicarDigitacao(new Conversa, new User, true);
        $publicador->publicarDigitacao(new Conversa, new User, true);
        Event::assertNotDispatched(ParticipanteDigitando::class);
    }

    public function test_mensagem_sem_conversa_nao_vaza_para_canais(): void
    {
        Event::fake([MensagemAlterada::class]);
        $mensagem = new Mensagem;
        $mensagem->setRelation('conversa', null)->setRelation('remetente', null);
        app(PublicadorMensagem::class)->publicarAlteracao($mensagem);
        Event::assertDispatched(MensagemAlterada::class, fn ($evento) => $evento->broadcastOn() === []);
    }
}
