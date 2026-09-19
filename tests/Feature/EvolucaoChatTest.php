<?php

namespace Tests\Feature;

use App\Enums\TipoConversa;
use App\Events\MensagemAlterada;
use App\Events\MensagemEnviada;
use App\Events\ParticipanteDigitando;
use App\Models\Bloqueio;
use App\Models\Conversa;
use App\Models\Mensagem;
use App\Models\User;
use App\Services\ServicoConversa;
use App\Services\ServicoLeitura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EvolucaoChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([MensagemEnviada::class, MensagemAlterada::class, ParticipanteDigitando::class]);
    }

    public function test_lista_ordena_pela_ultima_mensagem_enviada_e_edicao_nao_promove(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-19 12:00:00', 'UTC'));

        $yoshi = User::factory()->create(['name' => 'Yoshi Lista']);
        $carla = User::factory()->create(['name' => 'Carla Lista']);
        $bruno = User::factory()->create(['name' => 'Bruno Lista']);

        $this->actingAs($yoshi)->post('/mensagens', [
            'destinatario_id' => $carla->id,
            'conteudo' => 'Primeira para Carla',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-19 12:05:00', 'UTC'));

        $this->actingAs($yoshi)->post('/mensagens', [
            'destinatario_id' => $bruno->id,
            'conteudo' => 'Depois para Bruno',
        ]);

        $html = $this->actingAs($yoshi)->get('/')->getContent();
        $this->assertLessThan(strpos($html, 'Carla Lista'), strpos($html, 'Bruno Lista'));
        $this->assertStringContainsString('Depois para Bruno', $html);
        $this->assertStringContainsString('Primeira para Carla', $html);

        $antiga = Mensagem::query()->where('conteudo', 'Primeira para Carla')->first();
        Carbon::setTestNow(Carbon::parse('2026-09-19 13:00:00', 'UTC'));
        $this->actingAs($yoshi)->patch('/mensagens/'.$antiga->id, [
            'conteudo' => 'Carla editada muito depois',
        ]);

        $htmlEditado = $this->actingAs($yoshi)->get('/')->getContent();
        $this->assertLessThan(strpos($htmlEditado, 'Carla Lista'), strpos($htmlEditado, 'Bruno Lista'));
        $this->assertStringContainsString('Carla editada muito depois', $htmlEditado);
    }

    public function test_previa_trata_remocao_e_anexo_sem_consulta_por_contato(): void
    {
        Storage::fake('anexos');
        $remetente = User::factory()->create(['name' => 'Ana Previa']);
        $destinatario = User::factory()->create(['name' => 'Beto Previa']);

        $this->actingAs($remetente)->post('/mensagens', [
            'destinatario_id' => $destinatario->id,
            'conteudo' => '',
            'anexo' => UploadedFile::fake()->image('foto.jpg', 20, 20),
        ]);

        $this->actingAs($remetente)
            ->get('/')
            ->assertOk()
            ->assertSee('Imagem')
            ->assertSee('Beto Previa');

        $mensagem = Mensagem::query()->first();
        $this->actingAs($remetente)->delete('/mensagens/'.$mensagem->id);

        $this->actingAs($remetente)
            ->get('/')
            ->assertSee('Mensagem removida');
    }

    public function test_get_nao_marca_leitura_e_contagem_ignora_proprias_e_removidas(): void
    {
        $alice = User::factory()->create();
        $bruno = User::factory()->create();

        $this->actingAs($bruno)->post('/mensagens', [
            'destinatario_id' => $alice->id,
            'conteudo' => 'Oi Alice',
        ]);
        $this->actingAs($alice)->post('/mensagens', [
            'destinatario_id' => $bruno->id,
            'conteudo' => 'Resposta',
        ]);

        $recebida = Mensagem::query()->where('conteudo', 'Oi Alice')->first();
        $conversa = $recebida->conversa;

        $this->assertSame(1, app(ServicoLeitura::class)->quantidade($alice, $conversa));

        $this->actingAs($alice)->get('/?contato='.$bruno->id)->assertOk();
        $this->actingAs($alice)->getJson('/mensagens?contato='.$bruno->id)->assertOk();

        $participante = $conversa->participanteAtivo($alice);
        $this->assertNull($participante->ultima_leitura_mensagem_id);
        $this->assertSame(1, app(ServicoLeitura::class)->quantidade($alice, $conversa));

        $this->actingAs($bruno)->deleteJson('/mensagens/'.$recebida->id)->assertOk();
        $this->assertSame(0, app(ServicoLeitura::class)->quantidade($alice, $conversa->fresh()));
    }

    public function test_lista_html_mostra_badge_numerico_de_nao_lidas(): void
    {
        $alice = User::factory()->create();
        $bruno = User::factory()->create(['name' => 'Bruno Badge']);

        $this->actingAs($bruno)->post('/mensagens', [
            'destinatario_id' => $alice->id,
            'conteudo' => 'Não lida visível',
        ]);

        $this->actingAs($alice)
            ->get('/')
            ->assertOk()
            ->assertSee('aria-label="1 não lidas"', false)
            ->assertSee('Não lida visível');
    }

    public function test_leitura_e_monotonica_entre_marcadores(): void
    {
        $alice = User::factory()->create();
        $bruno = User::factory()->create();

        $this->actingAs($bruno)->post('/mensagens', [
            'destinatario_id' => $alice->id,
            'conteudo' => 'Um',
        ]);
        $this->actingAs($bruno)->post('/mensagens', [
            'destinatario_id' => $alice->id,
            'conteudo' => 'Dois',
        ]);

        $um = Mensagem::query()->where('conteudo', 'Um')->first();
        $dois = Mensagem::query()->where('conteudo', 'Dois')->first();
        $conversa = $um->conversa;

        $this->actingAs($alice)->postJson('/leituras', [
            'conversa_id' => $conversa->id,
            'ate_id' => $dois->id,
        ])->assertOk();

        $this->actingAs($alice)->postJson('/leituras', [
            'conversa_id' => $conversa->id,
            'ate_id' => $um->id,
        ])->assertOk();

        $this->assertSame($dois->id, (int) $conversa->participanteAtivo($alice)->fresh()->ultima_leitura_mensagem_id);
    }

    public function test_digitacao_nao_envia_rascunho_e_respeita_bloqueio(): void
    {
        $alice = User::factory()->create(['name' => 'Alice Digita']);
        $bruno = User::factory()->create(['name' => 'Bruno Digita']);

        $this->actingAs($alice)->post('/mensagens', [
            'destinatario_id' => $bruno->id,
            'conteudo' => 'base',
        ]);
        $conversa = Mensagem::query()->first()->conversa;

        $this->actingAs($alice)->postJson('/digitacao', [
            'conversa_id' => $conversa->id,
            'digitando' => true,
            'conteudo' => 'segredo do rascunho',
        ])->assertNoContent();

        Event::assertDispatched(ParticipanteDigitando::class, function (ParticipanteDigitando $evento) use ($alice, $bruno, $conversa) {
            $payload = $evento->broadcastWith();
            $canais = collect($evento->broadcastOn())->map(fn ($canal) => $canal->name);

            return $payload['conversa_id'] === $conversa->id
                && $payload['usuario_id'] === $alice->id
                && $payload['digitando'] === true
                && ! isset($payload['conteudo'])
                && $canais->contains('private-App.Models.User.'.$bruno->id)
                && ! $canais->contains('private-App.Models.User.'.$alice->id);
        });

        $this->actingAs($alice)->post('/bloqueios', ['user_id' => $bruno->id]);

        Event::fake([ParticipanteDigitando::class]);

        $this->actingAs($alice)->postJson('/digitacao', [
            'conversa_id' => $conversa->id,
            'digitando' => true,
        ])->assertForbidden();

        $this->actingAs($bruno)->postJson('/digitacao', [
            'conversa_id' => $conversa->id,
            'digitando' => true,
        ])->assertForbidden();
    }

    public function test_autor_edita_e_remove_e_terceiro_e_recusado(): void
    {
        $autor = User::factory()->create();
        $contato = User::factory()->create();
        $intruso = User::factory()->create();

        $this->actingAs($autor)->post('/mensagens', [
            'destinatario_id' => $contato->id,
            'conteudo' => 'Original',
        ]);
        $mensagem = Mensagem::query()->first();

        $this->actingAs($intruso)->patchJson('/mensagens/'.$mensagem->id, [
            'conteudo' => 'Hack',
        ])->assertForbidden();

        $this->actingAs($contato)->deleteJson('/mensagens/'.$mensagem->id)->assertForbidden();

        $this->actingAs($autor)->patchJson('/mensagens/'.$mensagem->id, [
            'conteudo' => 'Editada pelo autor',
        ])->assertOk()->assertJsonPath('mensagem.editada', true);

        $this->actingAs($autor)->deleteJson('/mensagens/'.$mensagem->id)
            ->assertOk()
            ->assertJsonPath('mensagem.removida', true);

        $this->actingAs($autor)->patchJson('/mensagens/'.$mensagem->id, [
            'conteudo' => 'voltar',
        ])->assertForbidden();

        $this->actingAs($contato)
            ->get('/?contato='.$autor->id)
            ->assertSee('Mensagem removida')
            ->assertDontSee('Editada pelo autor');
    }

    public function test_historico_inicia_nas_ultimas_50_e_carrega_anteriores_por_cursor(): void
    {
        $alice = User::factory()->create();
        $bruno = User::factory()->create();
        $conversa = app(ServicoConversa::class)->individualEntre($alice, $bruno);

        foreach (range(1, 55) as $indice) {
            Mensagem::factory()->create([
                'conversa_id' => $conversa->id,
                'remetente_id' => $alice->id,
                'destinatario_id' => $bruno->id,
                'conteudo' => 'Msg '.$indice,
                'created_at' => Carbon::parse('2026-09-19 10:00:00')->addMinutes($indice),
            ]);
        }

        $pagina = $this->actingAs($alice)
            ->getJson('/mensagens?contato='.$bruno->id)
            ->assertOk()
            ->assertJsonPath('tem_anteriores', true)
            ->json('mensagens');

        $this->assertCount(50, $pagina);
        $this->assertSame('Msg 6', $pagina[0]['conteudo']);
        $this->assertSame('Msg 55', $pagina[49]['conteudo']);

        $anteriores = $this->actingAs($alice)
            ->getJson('/mensagens?contato='.$bruno->id.'&antes_id='.$pagina[0]['id'])
            ->assertOk()
            ->json('mensagens');

        $this->assertCount(5, $anteriores);
        $this->assertSame('Msg 1', $anteriores[0]['conteudo']);
        $this->assertSame('Msg 5', $anteriores[4]['conteudo']);
    }

    public function test_reconciliacao_por_versao_devolve_edicoes_e_exclusoes(): void
    {
        $alice = User::factory()->create();
        $bruno = User::factory()->create();

        $this->actingAs($alice)->post('/mensagens', [
            'destinatario_id' => $bruno->id,
            'conteudo' => 'Viva',
        ]);
        $mensagem = Mensagem::query()->first();
        $versaoInicial = (int) $mensagem->conversa->versao;

        $this->actingAs($alice)->patchJson('/mensagens/'.$mensagem->id, [
            'conteudo' => 'Editada depois da queda',
        ]);

        $this->actingAs($alice)
            ->getJson('/mensagens?contato='.$bruno->id.'&versao='.$versaoInicial)
            ->assertOk()
            ->assertJsonPath('mensagens.0.conteudo', 'Editada depois da queda')
            ->assertJsonPath('mensagens.0.editada', true);
    }

    public function test_grupo_com_tres_membros_e_quarto_sem_acesso(): void
    {
        $criador = User::factory()->create(['name' => 'Criador']);
        $ana = User::factory()->create(['name' => 'Ana Grupo']);
        $bia = User::factory()->create(['name' => 'Bia Grupo']);
        $caos = User::factory()->create(['name' => 'Caos']);

        $this->actingAs($criador)->post('/grupos', [
            'nome' => 'Time Alpha',
            'membros' => [$ana->id, $bia->id],
        ])->assertRedirect();

        $grupo = Conversa::query()->where('tipo', TipoConversa::Grupo)->first();
        $this->assertNotNull($grupo);

        $this->actingAs($criador)->post('/mensagens', [
            'conversa_id' => $grupo->id,
            'conteudo' => 'Olá grupo',
        ]);

        $this->actingAs($ana)
            ->get('/?grupo='.$grupo->id)
            ->assertOk()
            ->assertSee('Olá grupo')
            ->assertSee('Criador');

        $this->actingAs($caos)
            ->get('/?grupo='.$grupo->id)
            ->assertNotFound();

        $this->actingAs($caos)
            ->getJson('/mensagens?conversa='.$grupo->id)
            ->assertForbidden();

        $this->actingAs($caos)->postJson('/mensagens', [
            'conversa_id' => $grupo->id,
            'conteudo' => 'Intruso',
        ])->assertForbidden();

        Event::assertDispatched(MensagemEnviada::class, function (MensagemEnviada $evento) use ($criador, $ana, $bia, $caos) {
            $canais = collect($evento->broadcastOn())->map(fn ($canal) => $canal->name);

            return $canais->contains('private-App.Models.User.'.$criador->id)
                && $canais->contains('private-App.Models.User.'.$ana->id)
                && $canais->contains('private-App.Models.User.'.$bia->id)
                && ! $canais->contains('private-App.Models.User.'.$caos->id);
        });
    }

    public function test_remover_membro_ja_conectado_corta_historico_e_eventos(): void
    {
        $criador = User::factory()->create();
        $ana = User::factory()->create();
        $bia = User::factory()->create();

        $this->actingAs($criador)->post('/grupos', [
            'nome' => 'Corte',
            'membros' => [$ana->id, $bia->id],
        ]);
        $grupo = Conversa::query()->where('nome', 'Corte')->first();

        $this->actingAs($criador)->delete('/grupos/'.$grupo->id.'/membros/'.$ana->id);

        $this->actingAs($ana)
            ->get('/?grupo='.$grupo->id)
            ->assertNotFound();

        $this->actingAs($criador)->post('/mensagens', [
            'conversa_id' => $grupo->id,
            'conteudo' => 'Depois da remoção',
        ]);

        Event::assertDispatched(MensagemEnviada::class, function (MensagemEnviada $evento) use ($ana, $grupo) {
            if (($evento->broadcastWith()['conteudo'] ?? '') !== 'Depois da remoção') {
                return true;
            }

            $canais = collect($evento->broadcastOn())->map(fn ($canal) => $canal->name);

            return ! $canais->contains('private-App.Models.User.'.$ana->id)
                && $evento->broadcastWith()['conversa_id'] === $grupo->id;
        });
    }

    public function test_anexo_valido_invalido_acima_do_limite_e_acesso(): void
    {
        Storage::fake('anexos');
        $alice = User::factory()->create();
        $bruno = User::factory()->create();
        $carla = User::factory()->create();

        $this->actingAs($alice)->post('/mensagens', [
            'destinatario_id' => $bruno->id,
            'conteudo' => 'Com foto',
            'anexo' => UploadedFile::fake()->image('ok.png', 10, 10),
        ])->assertRedirect();

        $mensagem = Mensagem::query()->first();
        $this->assertNotNull($mensagem->anexo_caminho);
        $this->assertTrue(Storage::disk('anexos')->exists($mensagem->anexo_caminho));

        $this->actingAs($alice)
            ->get(route('mensagens.anexo', $mensagem))
            ->assertOk();
        $this->actingAs($bruno)
            ->get(route('mensagens.anexo', $mensagem))
            ->assertOk();
        $this->actingAs($carla)
            ->get(route('mensagens.anexo', $mensagem))
            ->assertForbidden();

        $this->actingAs($alice)->post('/mensagens', [
            'destinatario_id' => $bruno->id,
            'conteudo' => 'pdf',
            'anexo' => UploadedFile::fake()->create('doc.pdf', 20, 'application/pdf'),
        ])->assertSessionHasErrors('anexo');

        $this->actingAs($alice)->post('/mensagens', [
            'destinatario_id' => $bruno->id,
            'conteudo' => 'grande',
            'anexo' => UploadedFile::fake()->image('grande.jpg')->size(2049),
        ])->assertSessionHasErrors('anexo');

        $this->actingAs($alice)->delete('/mensagens/'.$mensagem->id);
        $this->actingAs($bruno)
            ->get(route('mensagens.anexo', $mensagem))
            ->assertForbidden();
    }

    public function test_bloqueio_impede_envio_individual_e_preserva_outras_conversas_e_grupos(): void
    {
        $alice = User::factory()->create(['name' => 'Alice Blq']);
        $bruno = User::factory()->create(['name' => 'Bruno Blq']);
        $carla = User::factory()->create(['name' => 'Carla Blq']);

        $this->actingAs($alice)->post('/mensagens', [
            'destinatario_id' => $bruno->id,
            'conteudo' => 'Antes do bloqueio',
        ]);
        $this->actingAs($alice)->post('/bloqueios', ['user_id' => $bruno->id])->assertRedirect();

        $this->actingAs($alice)->post('/mensagens', [
            'destinatario_id' => $bruno->id,
            'conteudo' => 'Não deve passar',
        ])->assertForbidden();

        $this->actingAs($bruno)->post('/mensagens', [
            'destinatario_id' => $alice->id,
            'conteudo' => 'Também não',
        ])->assertForbidden();

        $this->actingAs($alice)->post('/mensagens', [
            'destinatario_id' => $carla->id,
            'conteudo' => 'Carla segue',
        ])->assertRedirect(route('dashboard', ['contato' => $carla->id]));

        $this->actingAs($alice)->post('/grupos', [
            'nome' => 'Trio',
            'membros' => [$bruno->id, $carla->id],
        ]);
        $grupo = Conversa::query()->where('nome', 'Trio')->first();

        $this->actingAs($bruno)->post('/mensagens', [
            'conversa_id' => $grupo->id,
            'conteudo' => 'No grupo o bloqueio não esconde',
        ])->assertRedirect();

        $this->actingAs($alice)
            ->get('/?grupo='.$grupo->id)
            ->assertSee('No grupo o bloqueio não esconde');

        $this->actingAs($alice)
            ->get('/?contato='.$bruno->id)
            ->assertSee('Antes do bloqueio')
            ->assertSee('Você bloqueou este contato');

        $bloqueio = Bloqueio::query()->first();
        $this->actingAs($bruno)->delete('/bloqueios/'.$bloqueio->id)->assertForbidden();
        $this->actingAs($alice)->delete('/bloqueios/'.$bloqueio->id)->assertRedirect();

        $this->actingAs($bruno)->post('/mensagens', [
            'destinatario_id' => $alice->id,
            'conteudo' => 'Depois do desbloqueio',
        ])->assertRedirect();
    }

    public function test_links_seguros_no_historico_inicial(): void
    {
        $alice = User::factory()->create();
        $bruno = User::factory()->create();

        $this->actingAs($alice)->post('/mensagens', [
            'destinatario_id' => $bruno->id,
            'conteudo' => 'Abra https://exemplo.com e <b>html</b>',
        ]);

        $this->actingAs($bruno)
            ->get('/?contato='.$alice->id)
            ->assertOk()
            ->assertSee('rel="noopener noreferrer"', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('href="https://exemplo.com"', false)
            ->assertSee('&lt;b&gt;html&lt;/b&gt;', false)
            ->assertDontSee('<b>html</b>', false);
    }

    public function test_composer_multilinha_existe_no_html_sem_javascript(): void
    {
        $alice = User::factory()->create();
        $bruno = User::factory()->create();

        $this->actingAs($alice)
            ->get('/?contato='.$bruno->id)
            ->assertSee('<textarea', false)
            ->assertSee('name="conteudo"', false)
            ->assertSee('id="formulario-mensagem"', false);
    }
}
