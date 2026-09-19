<?php

namespace Tests\Feature;

use App\Models\Mensagem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ChatPageTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_user_sees_own_name_and_not_self_in_contacts(): void
    {
        $user = User::factory()->create([
            'name' => 'Yoshi Leach',
            'email' => 'yoshi@example.com',
        ]);
        $contato = User::factory()->create([
            'name' => 'Carla Mendes',
            'email' => 'carla.mendes@example.com',
        ]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('Yoshi Leach');
        $response->assertSee('Carla Mendes');
        $response->assertDontSee('carla.mendes@example.com');
        $response->assertDontSee('yoshi@example.com');
        $response->assertDontSee('?contato='.$user->id, false);
        $response->assertSee('?contato='.$contato->id, false);
        $response->assertDontSee($user->getAuthPassword());
        $response->assertDontSee('Ana Souza');
        $response->assertDontSee('Bruno Lima');
        $response->assertDontSee('Olá! Tudo bem por aí?');
        $response->assertSee('Selecione um contato');
        $response->assertSee('Digite sua mensagem');
        $response->assertSee('Sair');
        $response->assertSee('disabled', false);
        $response->assertSee('data-user-id="'.$user->id.'"', false);
        $response->assertSee('class="contact-status aguardando"', false);
        $response->assertSee('data-presenca-usuario="'.$contato->id.'"', false);
        $response->assertSee('aria-label="Presença a confirmar"', false);
        $response->assertDontSee('class="contact-status online"', false);
        $response->assertDontSee('class="contact-status offline"', false);
        $response->assertSee('id="conversation-loader" class="conversation-loader" hidden', false);
        $response->assertSee('Carregando conversa');
        $response->assertSee('aria-busy="false"', false);
        $response->assertSee('<title>'.config('app.name').'</title>', false);
        $response->assertSee('Selecione um contato à esquerda para escrever.');
        $response->assertSee('aria-label="Enviar"', false);
        $response->assertSee('aria-describedby="orientacao-composer"', false);
        $response->assertSee('class="previa"', false);
        $response->assertSee('Nenhuma mensagem ainda');
        $response->assertSee('id="profile-img"', false);
        $response->assertSee('data-presenca-usuario="'.$user->id.'"', false);
        $response->assertSee('class="aguardando"', false);
        $response->assertDontSee('expand-button');
        $response->assertDontSee('status-options');
        $response->assertDontSee('id="status-online"');
        $response->assertSee('id="aviso-sincronizacao"', false);
        $response->assertSee('id="anuncio-mensagens"', false);
        $response->assertSee('aria-live="polite"', false);
        $response->assertSee('id="sidebar-backdrop"', false);
        $response->assertSee('id="carregar-anteriores" class="carregar-anteriores" hidden', false);
        $response->assertSee('class="coluna-cabecalho"', false);
        $response->assertSee('id="nome-grupo"', false);
        $response->assertSee('id="modal-criar-grupo"', false);
        $response->assertSee('id="abrir-criar-grupo"', false);
        $response->assertSee('Novos membros podem consultar o histórico do grupo.');
        $response->assertDontSee('id="criar-grupo"', false);
    }

    public function test_empty_contacts_state_when_user_is_alone(): void
    {
        $user = User::factory()->create([
            'name' => 'Yoshi Leach',
        ]);

        $response = $this->actingAs($user)->get('/');

        $response->assertOk();
        $response->assertSee('Nenhum outro usuário cadastrado.');
        $response->assertSee('Selecione um contato');
        $response->assertDontSee('<li class="contact', false);
    }

    public function test_selected_contact_updates_header_and_is_highlighted(): void
    {
        $user = User::factory()->create([
            'name' => 'Yoshi Leach',
        ]);
        $contato = User::factory()->create([
            'name' => 'Carla Mendes',
            'email' => 'carla.mendes@example.com',
        ]);

        $response = $this->actingAs($user)->get('/?contato='.$contato->id);

        $response->assertOk();
        $response->assertSee('Carla Mendes');
        $response->assertSee('carla.mendes@example.com');
        $response->assertSee('class="contato-email"', false);
        $response->assertDontSee('Selecione um contato');
        $response->assertSee('class="contact active"', false);
        $response->assertSee('class="presenca-contato aguardando"', false);
        $response->assertSee('data-presenca-usuario="'.$contato->id.'"', false);
        $response->assertSee('Presença a confirmar');
        $response->assertDontSee('Olá! Tudo bem por aí?');
        $response->assertSee('Nenhuma mensagem nesta conversa.');
        $response->assertSee('id="carregar-anteriores" class="carregar-anteriores" hidden', false);
        $response->assertDontSee('placeholder="Digite sua mensagem…" disabled', false);
        $response->assertSee('name="conteudo"', false);
        $response->assertSee('maxlength="1000"', false);
        $response->assertSee('<title>Carla Mendes · '.config('app.name').'</title>', false);
        $response->assertSee('aria-current="page"', false);
        $response->assertDontSee('Selecione um contato à esquerda para escrever.');
        $response->assertSee('aria-label="Enviar"', false);
    }

    public function test_message_history_shows_localized_timestamps(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-19 15:30:00', 'UTC'));

        $user = User::factory()->create(['name' => 'Yoshi Leach']);
        $contato = User::factory()->create(['name' => 'Carla Mendes']);

        Mensagem::factory()->create([
            'remetente_id' => $user->id,
            'destinatario_id' => $contato->id,
            'conteudo' => 'Mensagem de hoje',
            'created_at' => Carbon::parse('2026-09-19 14:05:00', 'UTC'),
        ]);
        Mensagem::factory()->create([
            'remetente_id' => $contato->id,
            'destinatario_id' => $user->id,
            'conteudo' => 'Mensagem de março',
            'created_at' => Carbon::parse('2026-03-18 09:00:00', 'UTC'),
        ]);

        $this->actingAs($user)
            ->get('/?contato='.$contato->id)
            ->assertOk()
            ->assertSee('Mensagem de hoje')
            ->assertSee('14:05')
            ->assertSee('18/03, 09:00')
            ->assertSee('<time class="mensagem-horario"', false);
    }

    public function test_selecting_self_as_contact_is_not_found(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/?contato='.$user->id)
            ->assertNotFound();
    }

    public function test_selecting_missing_contact_is_not_found(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/?contato=99999')
            ->assertNotFound();
    }
}
