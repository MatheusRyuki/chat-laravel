<?php

namespace Tests\Feature;

use App\Events\MensagemEnviada;
use App\Events\MensagemAlterada;
use App\Events\ParticipanteAtualizado;
use App\Events\ParticipanteDigitando;
use App\Models\Mensagem;
use App\Models\User;
use App\Services\ServicoConversa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IntegridadeChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake([MensagemEnviada::class, MensagemAlterada::class, ParticipanteAtualizado::class, ParticipanteDigitando::class]);
    }

    public function test_grupo_json_readmite_sem_duplicar_e_restaura_acesso_ao_historico(): void
    {
        [$ana, $bia, $caio] = User::factory()->count(3)->create();
        $id = $this->actingAs($ana)->postJson('/grupos', ['nome' => 'Equipe', 'membros' => [$bia->id]])->assertCreated()->json('conversa_id');
        $this->postJson('/mensagens', ['conversa_id' => $id, 'conteudo' => 'Histórico da equipe'])->assertCreated();
        $this->postJson('/grupos/'.$id.'/membros', ['user_id' => $caio->id])->assertOk();
        $this->deleteJson('/grupos/'.$id.'/membros/'.$bia->id)->assertOk();
        $this->actingAs($bia)->getJson('/mensagens?conversa='.$id)->assertForbidden();
        $this->actingAs($ana)->postJson('/grupos/'.$id.'/membros', ['user_id' => $bia->id])->assertOk();
        $this->postJson('/grupos/'.$id.'/membros', ['user_id' => $bia->id])->assertOk();
        $this->assertDatabaseCount('conversa_participantes', 3);
        $this->actingAs($bia)->getJson('/mensagens?conversa='.$id)->assertOk()->assertJsonPath('mensagens.0.conteudo', 'Histórico da equipe');
        $this->actingAs($ana)->deleteJson('/grupos/'.$id.'/membros/'.$ana->id)->assertUnprocessable();
    }

    public function test_historico_vazio_incremental_e_cursor_de_outra_conversa(): void
    {
        [$ana, $bia, $caio] = User::factory()->count(3)->create();
        $this->actingAs($ana)->getJson('/mensagens?contato='.$bia->id)->assertExactJson(['mensagens' => [], 'tem_anteriores' => false, 'versao' => 0]);
        $this->getJson('/mensagens')->assertNotFound();
        $primeira = $this->postJson('/mensagens', ['destinatario_id' => $bia->id, 'conteudo' => 'Um'])->assertCreated()->json('mensagem.id');
        $this->postJson('/mensagens', ['destinatario_id' => $bia->id, 'conteudo' => 'Dois'])->assertCreated();
        $outra = $this->postJson('/mensagens', ['destinatario_id' => $caio->id, 'conteudo' => 'Privada'])->assertCreated()->json('mensagem.id');
        $this->getJson('/mensagens?contato='.$bia->id.'&depois_id='.$primeira)->assertJsonCount(1, 'mensagens')->assertJsonPath('mensagens.0.conteudo', 'Dois')->assertJsonPath('tem_anteriores', false);
        $this->getJson('/mensagens?contato='.$bia->id.'&antes_id='.$outra)->assertNotFound();
    }

    public function test_digitacao_limita_repeticao_e_parada_libera_novo_evento(): void
    {
        [$ana, $bia] = User::factory()->count(2)->create();
        $conversa = app(ServicoConversa::class)->individualEntre($ana, $bia);
        $this->actingAs($ana);
        $dados = ['conversa_id' => $conversa->id, 'digitando' => true];
        $this->postJson('/digitacao', $dados)->assertNoContent();
        $this->postJson('/digitacao', $dados)->assertNoContent();
        Event::assertDispatchedTimes(ParticipanteDigitando::class, 1);
        $this->postJson('/digitacao', array_replace($dados, ['digitando' => false]))->assertNoContent();
        $this->postJson('/digitacao', $dados)->assertNoContent();
        Event::assertDispatchedTimes(ParticipanteDigitando::class, 3);
        $this->travel(3)->seconds();
        $this->postJson('/digitacao', $dados)->assertNoContent();
        Event::assertDispatchedTimes(ParticipanteDigitando::class, 4);
    }

    public function test_anexo_entrega_bytes_e_cabecalhos_e_recusa_arquivo_ausente(): void
    {
        Storage::fake('anexos');
        [$ana, $bia] = User::factory()->count(2)->create();
        $arquivo = UploadedFile::fake()->image('foto.png', 20, 20);
        $bytes = file_get_contents($arquivo->getRealPath());
        $id = $this->actingAs($ana)->postJson('/mensagens', ['destinatario_id' => $bia->id, 'anexo' => $arquivo])->assertCreated()->json('mensagem.id');
        $resposta = $this->actingAs($bia)->get('/mensagens/'.$id.'/anexo')->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($bytes, $resposta->streamedContent());
        Storage::disk('anexos')->delete(Mensagem::findOrFail($id)->anexo_caminho);
        $this->get('/mensagens/'.$id.'/anexo')->assertNotFound();
    }

    public function test_falha_na_transacao_remove_anexo_e_reverte_versao(): void
    {
        Storage::fake('anexos');
        [$ana, $bia] = User::factory()->count(2)->create();
        $conversa = app(ServicoConversa::class)->individualEntre($ana, $bia);
        Mensagem::creating(fn () => throw new \RuntimeException('Falha simulada na persistência'));
        $this->actingAs($ana)->postJson('/mensagens', ['conversa_id' => $conversa->id, 'anexo' => UploadedFile::fake()->image('foto.jpg')])->assertStatus(500);
        $this->assertDatabaseCount('mensagens', 0);
        $this->assertSame(0, $conversa->fresh()->versao);
        $this->assertSame([], Storage::disk('anexos')->allFiles());
        Event::assertNotDispatched(MensagemEnviada::class);
    }

    public function test_envio_exige_destino_e_grupo_recusa_imagem(): void
    {
        [$ana, $bia] = User::factory()->count(2)->create();
        $this->actingAs($ana)->postJson('/mensagens', ['conteudo' => 'Sem destino'])->assertJsonValidationErrors('destinatario_id');
        $grupo = app(ServicoConversa::class)->criarGrupo($ana, 'Grupo', [$bia]);
        $this->postJson('/mensagens', ['conversa_id' => $grupo->id, 'anexo' => UploadedFile::fake()->image('foto.png')])->assertJsonValidationErrors('anexo');
        $this->assertDatabaseCount('mensagens', 0);
    }

    public function test_autor_removido_ou_bloqueado_nao_edita_nem_exclui(): void
    {
        [$ana, $bia] = User::factory()->count(2)->create();
        $id = $this->actingAs($ana)->postJson('/mensagens', ['destinatario_id' => $bia->id, 'conteudo' => 'Original'])->json('mensagem.id');
        $this->actingAs($bia)->postJson('/bloqueios', ['user_id' => $ana->id])->assertRedirect();
        $this->actingAs($ana)->patchJson('/mensagens/'.$id, ['conteudo' => 'Mudou'])->assertForbidden();
        $this->deleteJson('/mensagens/'.$id)->assertForbidden();
        $this->assertSame('Original', Mensagem::findOrFail($id)->conteudo);
        $grupo = app(ServicoConversa::class)->criarGrupo($ana, 'Grupo', [$bia]);
        $id = $this->actingAs($bia)->postJson('/mensagens', ['conversa_id' => $grupo->id, 'conteudo' => 'Mensagem no grupo'])->json('mensagem.id');
        app(ServicoConversa::class)->removerMembro($grupo, $bia);
        $this->patchJson('/mensagens/'.$id, ['conteudo' => 'Mudou'])->assertForbidden();
        $this->deleteJson('/mensagens/'.$id)->assertForbidden();
    }
    public function test_arquivo_que_desaparece_antes_da_abertura_retorna_404(): void
    {
        Storage::fake('anexos');
        [$ana, $bia] = User::factory()->count(2)->create();
        $id = $this->actingAs($ana)->postJson('/mensagens', ['destinatario_id' => $bia->id, 'anexo' => UploadedFile::fake()->image('foto.png')])->assertCreated()->json('mensagem.id');
        $caminho = Mensagem::findOrFail($id)->anexo_caminho;
        $absoluto = Storage::disk('anexos')->path($caminho);
        $this->partialMock(\App\Services\ServicoAnexo::class, function ($mock) use ($caminho, $absoluto) {
            $mock->shouldReceive('existe')->once()->with($caminho)->andReturn(true);
            $mock->shouldReceive('caminhoAbsoluto')->once()->with($caminho)->andReturnUsing(function () use ($caminho, $absoluto) {
                Storage::disk('anexos')->delete($caminho);
                return $absoluto;
            });
        });
        $this->actingAs($bia)->get('/mensagens/'.$id.'/anexo')->assertNotFound();
    }

}
