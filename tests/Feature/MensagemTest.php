<?php

namespace Tests\Feature;

use App\Broadcasting\PublicadorMensagem;
use App\Events\MensagemEnviada;
use App\Models\Mensagem;
use App\Models\User;
use App\Services\ServicoBloqueio;
use App\Services\ServicoConversa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class MensagemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([MensagemEnviada::class]);
    }

    public function test_guest_cannot_send_message(): void
    {
        $destinatario = User::factory()->create();

        $this->post('/mensagens', [
            'destinatario_id' => $destinatario->id,
            'conteudo' => 'Olá',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('mensagens', 0);
    }

    public function test_authenticated_user_can_send_message_to_selected_contact(): void
    {
        $remetente = User::factory()->create();
        $destinatario = User::factory()->create();

        $response = $this->actingAs($remetente)->post('/mensagens', [
            'destinatario_id' => $destinatario->id,
            'conteudo' => 'Olá, Carla!',
        ]);

        $response->assertRedirect(route('dashboard', ['contato' => $destinatario->id]));
        $this->assertDatabaseHas('mensagens', [
            'remetente_id' => $remetente->id,
            'destinatario_id' => $destinatario->id,
            'conteudo' => 'Olá, Carla!',
        ]);

        $this->actingAs($remetente)
            ->get('/?contato='.$destinatario->id)
            ->assertOk()
            ->assertSee('Olá, Carla!')
            ->assertDontSee('Nenhuma mensagem nesta conversa.');
    }

    public function test_empty_and_whitespace_content_is_rejected_and_old_input_is_preserved(): void
    {
        $remetente = User::factory()->create();
        $destinatario = User::factory()->create();

        $this->actingAs($remetente)
            ->from('/?contato='.$destinatario->id)
            ->post('/mensagens', [
                'destinatario_id' => $destinatario->id,
                'conteudo' => '   ',
            ])
            ->assertRedirect('/?contato='.$destinatario->id)
            ->assertSessionHasErrors('conteudo');

        $this->assertDatabaseCount('mensagens', 0);
    }

    public function test_content_above_limit_is_rejected_and_old_input_is_preserved(): void
    {
        $remetente = User::factory()->create();
        $destinatario = User::factory()->create();
        $conteudo = str_repeat('a', Mensagem::TAMANHO_MAXIMO + 1);

        $this->actingAs($remetente)
            ->from('/?contato='.$destinatario->id)
            ->followingRedirects()
            ->post('/mensagens', [
                'destinatario_id' => $destinatario->id,
                'conteudo' => $conteudo,
            ])
            ->assertSee($conteudo, false)
            ->assertSee('A mensagem deve ter no máximo '.Mensagem::TAMANHO_MAXIMO);

        $this->assertDatabaseCount('mensagens', 0);
    }

    public function test_cannot_send_to_self_or_missing_recipient(): void
    {
        $remetente = User::factory()->create();

        $this->actingAs($remetente)
            ->post('/mensagens', [
                'destinatario_id' => $remetente->id,
                'conteudo' => 'Espelho',
            ])
            ->assertSessionHasErrors('destinatario_id');

        $this->actingAs($remetente)
            ->post('/mensagens', [
                'destinatario_id' => 99999,
                'conteudo' => 'Fantasma',
            ])
            ->assertSessionHasErrors('destinatario_id');

        $this->assertDatabaseCount('mensagens', 0);
    }

    public function test_forged_sender_id_is_ignored(): void
    {
        $remetente = User::factory()->create();
        $destinatario = User::factory()->create();
        $intruso = User::factory()->create();

        $this->actingAs($remetente)->post('/mensagens', [
            'remetente_id' => $intruso->id,
            'destinatario_id' => $destinatario->id,
            'conteudo' => 'Sou eu mesmo',
        ])->assertRedirect(route('dashboard', ['contato' => $destinatario->id]));

        $this->assertDatabaseHas('mensagens', [
            'remetente_id' => $remetente->id,
            'destinatario_id' => $destinatario->id,
            'conteudo' => 'Sou eu mesmo',
        ]);
        $this->assertDatabaseMissing('mensagens', [
            'remetente_id' => $intruso->id,
        ]);
    }

    public function test_conversation_history_is_isolated_from_third_user(): void
    {
        $alice = User::factory()->create(['name' => 'Alice']);
        $bruno = User::factory()->create(['name' => 'Bruno']);
        $carla = User::factory()->create(['name' => 'Carla']);

        Mensagem::factory()->create([
            'remetente_id' => $alice->id,
            'destinatario_id' => $bruno->id,
            'conteudo' => 'Segredo Alice-Bruno',
        ]);
        Mensagem::factory()->create([
            'remetente_id' => $alice->id,
            'destinatario_id' => $carla->id,
            'conteudo' => 'Oi Carla',
        ]);

        $this->actingAs($alice)
            ->get('/?contato='.$carla->id)
            ->assertOk()
            ->assertSee('Oi Carla');
        $this->assertHistoricoContem($this->actingAs($alice)->get('/?contato='.$carla->id), 'Oi Carla');
        $this->assertHistoricoNaoContem($this->actingAs($alice)->get('/?contato='.$carla->id), 'Segredo Alice-Bruno');

        $this->actingAs($carla)
            ->get('/?contato='.$alice->id)
            ->assertOk();
        $this->assertHistoricoContem($this->actingAs($carla)->get('/?contato='.$alice->id), 'Oi Carla');
        $this->assertHistoricoNaoContem($this->actingAs($carla)->get('/?contato='.$alice->id), 'Segredo Alice-Bruno');

        $this->actingAs($bruno)
            ->get('/?contato='.$alice->id)
            ->assertOk();
        $this->assertHistoricoContem($this->actingAs($bruno)->get('/?contato='.$alice->id), 'Segredo Alice-Bruno');
        $this->assertHistoricoNaoContem($this->actingAs($bruno)->get('/?contato='.$alice->id), 'Oi Carla');
    }

    private function trechoHistorico(TestResponse $resposta): string
    {
        $html = $resposta->getContent();
        preg_match('/id="lista-mensagens"(.*?)<\/ul>/s', $html, $partes);

        return $partes[1] ?? '';
    }

    private function assertHistoricoContem(TestResponse $resposta, string $texto): void
    {
        $this->assertStringContainsString($texto, $this->trechoHistorico($resposta));
    }

    private function assertHistoricoNaoContem(TestResponse $resposta, string $texto): void
    {
        $this->assertStringNotContainsString($texto, $this->trechoHistorico($resposta));
    }

    public function test_html_in_content_is_escaped(): void
    {
        $remetente = User::factory()->create();
        $destinatario = User::factory()->create();

        $this->actingAs($remetente)->post('/mensagens', [
            'destinatario_id' => $destinatario->id,
            'conteudo' => '<script>alert(1)</script>',
        ]);

        $this->actingAs($remetente)
            ->get('/?contato='.$destinatario->id)
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    public function test_store_dispatches_mensagem_enviada_on_both_private_channels(): void
    {
        $remetente = User::factory()->create();
        $destinatario = User::factory()->create();

        $this->actingAs($remetente)->post('/mensagens', [
            'destinatario_id' => $destinatario->id,
            'conteudo' => 'Ao vivo',
        ]);

        Event::assertDispatched(MensagemEnviada::class, function (MensagemEnviada $evento) use ($remetente, $destinatario) {
            $canais = collect($evento->broadcastOn())->map(fn ($canal) => $canal->name);
            $payload = $evento->broadcastWith();

            return $evento->broadcastAs() === 'mensagem.enviada'
                && $canais->contains('private-App.Models.User.'.$remetente->id)
                && $canais->contains('private-App.Models.User.'.$destinatario->id)
                && $payload['conteudo'] === 'Ao vivo'
                && $payload['remetente_id'] === $remetente->id
                && $payload['destinatario_id'] === $destinatario->id
                && isset($payload['id'], $payload['created_at'], $payload['conversa_id'], $payload['versao'])
                && ! array_key_exists('password', $payload)
                && ! array_key_exists('email', $payload);
        });
    }

    public function test_broadcast_failure_does_not_undo_saved_message(): void
    {
        $avisos = [];

        Event::listen(MessageLogged::class, function ($evento) use (&$avisos) {
            $avisos[] = $evento;
        });

        $conversas = $this->app->make(ServicoConversa::class);
        $bloqueios = $this->app->make(ServicoBloqueio::class);

        $this->app->instance(PublicadorMensagem::class, new class($conversas, $bloqueios) extends PublicadorMensagem
        {
            protected function disparar(Mensagem $mensagem): void
            {
                throw new \RuntimeException('pusher down');
            }
        });

        $remetente = User::factory()->create();
        $destinatario = User::factory()->create();

        $this->actingAs($remetente)
            ->post('/mensagens', [
                'destinatario_id' => $destinatario->id,
                'conteudo' => 'Salva mesmo assim',
            ])
            ->assertRedirect(route('dashboard', ['contato' => $destinatario->id]))
            ->assertSessionMissing('errors');

        $this->assertDatabaseHas('mensagens', [
            'remetente_id' => $remetente->id,
            'destinatario_id' => $destinatario->id,
            'conteudo' => 'Salva mesmo assim',
        ]);

        $aviso = collect($avisos)->first(
            fn ($evento) => $evento->level === 'warning'
                && $evento->message === 'Falha ao publicar mensagem no Pusher.'
        );

        $this->assertNotNull($aviso);
        $this->assertSame('pusher down', $aviso->context['erro'] ?? null);
        $this->assertStringNotContainsString('PUSHER_APP_SECRET', json_encode($aviso->context));
    }

    public function test_guest_cannot_fetch_message_history(): void
    {
        $destinatario = User::factory()->create();

        $this->getJson('/mensagens?contato='.$destinatario->id)
            ->assertUnauthorized();
    }

    public function test_authorized_pair_can_fetch_json_history_without_third_party_messages(): void
    {
        $alice = User::factory()->create();
        $bruno = User::factory()->create();
        $carla = User::factory()->create();

        Mensagem::factory()->create([
            'remetente_id' => $alice->id,
            'destinatario_id' => $bruno->id,
            'conteudo' => 'Segredo Alice-Bruno',
        ]);
        $visivel = Mensagem::factory()->create([
            'remetente_id' => $alice->id,
            'destinatario_id' => $carla->id,
            'conteudo' => 'Oi Carla',
        ]);

        $this->actingAs($alice)
            ->getJson('/mensagens?contato='.$carla->id)
            ->assertOk()
            ->assertJsonCount(1, 'mensagens')
            ->assertJsonPath('mensagens.0.id', $visivel->id)
            ->assertJsonPath('mensagens.0.conteudo', 'Oi Carla')
            ->assertJsonMissing(['conteudo' => 'Segredo Alice-Bruno']);
    }

    public function test_json_history_rejects_self_and_missing_contact(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->getJson('/mensagens?contato='.$usuario->id)
            ->assertNotFound();

        $this->actingAs($usuario)
            ->getJson('/mensagens?contato=99999')
            ->assertNotFound();
    }

    public function test_json_store_returns_created_payload_without_replacing_html_post(): void
    {
        $remetente = User::factory()->create();
        $destinatario = User::factory()->create();

        $this->actingAs($remetente)
            ->postJson('/mensagens', [
                'destinatario_id' => $destinatario->id,
                'conteudo' => 'Envio assíncrono',
            ])
            ->assertCreated()
            ->assertJsonPath('mensagem.conteudo', 'Envio assíncrono')
            ->assertJsonPath('mensagem.remetente_id', $remetente->id)
            ->assertJsonPath('mensagem.destinatario_id', $destinatario->id)
            ->assertJsonMissingPath('mensagem.password')
            ->assertJsonMissingPath('mensagem.email');

        $this->assertDatabaseHas('mensagens', [
            'remetente_id' => $remetente->id,
            'destinatario_id' => $destinatario->id,
            'conteudo' => 'Envio assíncrono',
        ]);
    }

    public function test_json_store_returns_validation_errors_without_creating_message(): void
    {
        $remetente = User::factory()->create();
        $destinatario = User::factory()->create();

        $this->actingAs($remetente)
            ->postJson('/mensagens', [
                'destinatario_id' => $destinatario->id,
                'conteudo' => '   ',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('conteudo');

        $this->assertDatabaseCount('mensagens', 0);
    }

    public function test_json_store_ignores_forged_sender_id(): void
    {
        $remetente = User::factory()->create();
        $destinatario = User::factory()->create();
        $intruso = User::factory()->create();

        $this->actingAs($remetente)
            ->postJson('/mensagens', [
                'remetente_id' => $intruso->id,
                'destinatario_id' => $destinatario->id,
                'conteudo' => 'Ainda sou eu',
            ])
            ->assertCreated()
            ->assertJsonPath('mensagem.remetente_id', $remetente->id);

        $this->assertDatabaseMissing('mensagens', [
            'remetente_id' => $intruso->id,
        ]);
    }

    public function test_guest_json_store_is_unauthorized(): void
    {
        $destinatario = User::factory()->create();

        $this->postJson('/mensagens', [
            'destinatario_id' => $destinatario->id,
            'conteudo' => 'Olá',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('mensagens', 0);
    }
}
