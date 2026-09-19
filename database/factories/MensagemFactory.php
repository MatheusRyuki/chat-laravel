<?php

namespace Database\Factories;

use App\Models\Mensagem;
use App\Models\User;
use App\Services\ServicoConversa;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mensagem>
 */
class MensagemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'remetente_id' => User::factory(),
            'destinatario_id' => User::factory(),
            'conteudo' => fake()->sentence(),
            'versao' => 1,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (Mensagem $mensagem): void {
            if ($mensagem->conversa_id || ! $mensagem->remetente_id || ! $mensagem->destinatario_id) {
                return;
            }

            $remetente = User::query()->find($mensagem->remetente_id);
            $destinatario = User::query()->find($mensagem->destinatario_id);

            if ($remetente === null || $destinatario === null) {
                return;
            }

            $mensagem->conversa_id = app(ServicoConversa::class)
                ->individualEntre($remetente, $destinatario)
                ->id;
        })->afterCreating(function (Mensagem $mensagem): void {
            $conversa = $mensagem->conversa;

            if ($conversa === null) {
                return;
            }

            if ((int) $conversa->versao < (int) $mensagem->versao) {
                $conversa->versao = (int) $mensagem->versao;
            }

            $conversa->registrarEnvio($mensagem);
        });
    }
}
