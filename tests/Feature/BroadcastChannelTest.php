<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_visitante_nao_assina_canal_privado(): void
    {
        $usuario = User::factory()->create();

        $this->postJson('/broadcasting/auth', [
            'channel_name' => 'private-App.Models.User.'.$usuario->id,
            'socket_id' => '1234.5678',
        ])->assertForbidden();
    }

    public function test_dono_do_canal_privado_e_autorizado(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)
            ->postJson('/broadcasting/auth', [
                'channel_name' => 'private-App.Models.User.'.$usuario->id,
                'socket_id' => '1234.5678',
            ])
            ->assertSuccessful();
    }

    public function test_outro_usuario_e_recusado_no_canal_privado(): void
    {
        $dono = User::factory()->create();
        $outro = User::factory()->create();

        $this->actingAs($outro)
            ->postJson('/broadcasting/auth', [
                'channel_name' => 'private-App.Models.User.'.$dono->id,
                'socket_id' => '1234.5678',
            ])
            ->assertForbidden();
    }

    public function test_visitante_nao_assina_canal_de_presenca(): void
    {
        $this->postJson('/broadcasting/auth', [
            'channel_name' => 'presence-presenca.chat',
            'socket_id' => '1234.5678',
        ])->assertForbidden();
    }

    public function test_autenticado_recebe_apenas_identificacao_no_canal_de_presenca(): void
    {
        $usuario = User::factory()->create([
            'name' => 'Carla Mendes',
            'email' => 'carla.mendes@example.com',
        ]);

        $resposta = $this->actingAs($usuario)
            ->postJson('/broadcasting/auth', [
                'channel_name' => 'presence-presenca.chat',
                'socket_id' => '1234.5678',
            ]);

        $resposta->assertSuccessful();

        $dadosCanal = json_decode((string) $resposta->json('channel_data'), true);

        $this->assertIsArray($dadosCanal);
        $this->assertSame((string) $usuario->id, (string) $dadosCanal['user_id']);
        $this->assertSame([
            'id' => $usuario->id,
            'name' => 'Carla Mendes',
        ], $dadosCanal['user_info']);
        $this->assertArrayNotHasKey('email', $dadosCanal['user_info']);
        $this->assertStringNotContainsString('carla.mendes@example.com', $resposta->getContent());
        $this->assertStringNotContainsString($usuario->getAuthPassword(), $resposta->getContent());
    }
}
