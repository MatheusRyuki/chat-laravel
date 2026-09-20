<?php

namespace Tests\Feature;

use App\Events\DiagnosticoPusher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DiagnosticoPusherTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_inexistente_nao_publica_evento(): void
    {
        Event::fake([DiagnosticoPusher::class]);
        $this->artisan('chat:diagnostico-pusher', ['usuario' => 'ausente@example.test'])->expectsOutput('Usuário não encontrado.')->assertFailed();
        Event::assertNotDispatched(DiagnosticoPusher::class);
    }

    public function test_configuracao_incompleta_nao_publica_evento(): void
    {
        Event::fake([DiagnosticoPusher::class]);
        $usuario = User::factory()->create();
        foreach (['key', 'secret', 'app_id', 'options.cluster'] as $campo) {
            $anterior = config('broadcasting.connections.pusher.'.$campo);
            config(['broadcasting.connections.pusher.'.$campo => '']);
            $this->artisan('chat:diagnostico-pusher', ['usuario' => $usuario->id])->assertFailed();
            config(['broadcasting.connections.pusher.'.$campo => $anterior]);
        }
        Event::assertNotDispatched(DiagnosticoPusher::class);
    }

    public function test_identifica_usuario_por_id_e_email(): void
    {
        Event::fake([DiagnosticoPusher::class]);
        $usuario = User::factory()->create();
        foreach ([$usuario->id, $usuario->email] as $identificador) {
            $this->artisan('chat:diagnostico-pusher', ['usuario' => $identificador])->assertSuccessful();
        }
        Event::assertDispatchedTimes(DiagnosticoPusher::class, 2);
        Event::assertDispatched(DiagnosticoPusher::class, fn ($evento) => $evento->usuario->is($usuario) && $evento->mensagem === 'Evento técnico fictício.');
    }
}
