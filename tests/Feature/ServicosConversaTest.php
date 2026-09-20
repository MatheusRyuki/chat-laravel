<?php

namespace Tests\Feature;

use App\Models\Bloqueio;
use App\Models\Conversa;
use App\Models\Mensagem;
use App\Models\User;
use App\Services\MontadorListaConversas;
use App\Services\ServicoBloqueio;
use App\Services\ServicoConversa;
use App\Services\ServicoLeitura;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ServicosConversaTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversa_individual_reutiliza_par_independente_da_ordem(): void
    {
        [$ana, $bia] = User::factory()->count(2)->create();
        $servico = app(ServicoConversa::class);
        $conversa = $servico->individualEntre($ana, $bia);
        $this->assertTrue($conversa->is($servico->individualEntre($bia, $ana)));
        $this->assertDatabaseCount('conversas', 1);
        $this->assertDatabaseCount('conversa_participantes', 2);
        $this->assertTrue($conversa->criador->is($ana));
        $this->assertTrue($conversa->participante($ana)->conversa->is($conversa));
        $mensagem = Mensagem::factory()->create(['conversa_id' => $conversa->id, 'remetente_id' => $ana->id, 'destinatario_id' => $bia->id]);
        $this->assertTrue($conversa->mensagens->sole()->is($mensagem));
        $this->assertTrue($mensagem->destinatario->is($bia));
    }

    public function test_conflito_de_criacao_recupera_conversa_gravada_por_outro_processo(): void
    {
        [$ana, $bia] = User::factory()->count(2)->create();
        $servico = app(ServicoConversa::class);
        // Simula a janela entre o SELECT inicial e o INSERT, sem depender de agendamento do SO.
        $gerenciador = DB::getFacadeRoot();
        DB::partialMock()->shouldReceive('transaction')->once()->andReturnUsing(function () use ($ana, $bia) {
            Conversa::factory()->create(['chave_par' => min($ana->id, $bia->id).':'.max($ana->id, $bia->id), 'criador_id' => $ana->id]);
            throw new QueryException('sqlite', 'insert into conversas', [], new \Exception('unique constraint'));
        });
        try {
            $conversa = $servico->individualEntre($ana, $bia);
        } finally {
            DB::swap($gerenciador);
        }
        $this->assertSame($ana->id.':'.$bia->id, $conversa->chave_par);
        $this->assertDatabaseCount('conversas', 1);
    }

    public function test_remover_nao_membro_e_idempotente_e_grupo_nao_tem_outro_contato(): void
    {
        [$ana, $bia] = User::factory()->count(2)->create();
        $grupo = app(ServicoConversa::class)->criarGrupo($ana, 'Equipe', []);
        app(ServicoConversa::class)->removerMembro($grupo, $bia);
        $this->assertDatabaseCount('conversa_participantes', 1);
        $this->assertNull($grupo->outroParticipante($ana));
        $this->assertFalse(app(ServicoBloqueio::class)->conversaIndividualBloqueada($grupo, $ana));
        $this->assertSame(0, app(ServicoLeitura::class)->quantidade($bia, $grupo));
    }

    public function test_conversa_com_participante_excluido_nao_bloqueia_o_remanescente(): void
    {
        [$ana, $bia] = User::factory()->count(2)->create();
        $conversa = app(ServicoConversa::class)->individualEntre($ana, $bia);
        $bia->delete();
        $conversa->refresh();
        $this->assertFalse(app(ServicoBloqueio::class)->conversaIndividualBloqueada($conversa, $ana));
        $this->assertSame([$ana->id], app(ServicoConversa::class)->idsAutorizadosParaEvento($conversa)->all());
    }

    public function test_servico_de_leitura_recusa_nao_membro(): void
    {
        [$ana, $bia, $caio] = User::factory()->count(3)->create();
        $conversa = app(ServicoConversa::class)->individualEntre($ana, $bia);
        try {
            app(ServicoLeitura::class)->registrar($caio, $conversa, 1);
            $this->fail('Leitura de não membro deveria ser recusada.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_servico_de_leitura_recusa_mensagem_de_outra_conversa(): void
    {
        [$ana, $bia, $caio] = User::factory()->count(3)->create();
        $conversa = app(ServicoConversa::class)->individualEntre($ana, $bia);
        $outra = app(ServicoConversa::class)->individualEntre($ana, $caio);
        $mensagem = Mensagem::factory()->create(['conversa_id' => $outra->id, 'remetente_id' => $caio->id, 'destinatario_id' => $ana->id]);
        try {
            app(ServicoLeitura::class)->registrar($ana, $conversa, $mensagem->id);
            $this->fail('Marcador externo deveria ser recusado.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertNull($conversa->participanteAtivo($ana)->ultima_leitura_mensagem_id);
    }

    public function test_lista_desempata_contatos_homonimos_por_id(): void
    {
        $ana = User::factory()->create();
        $contatos = User::factory()->count(2)->create(['name' => 'Mesmo nome']);
        $lista = app(MontadorListaConversas::class)->montar($ana, null, null);
        $this->assertSame($contatos->pluck('id')->all(), $lista->pluck('contato_id')->all());
        $bloqueio = app(ServicoBloqueio::class)->bloquear($ana, $contatos[0]);
        $this->assertTrue($bloqueio->bloqueador->is($ana));
    }
}
