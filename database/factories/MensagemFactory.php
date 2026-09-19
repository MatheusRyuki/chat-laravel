<?php

namespace Database\Factories;

use App\Models\Mensagem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Mensagem>
 */
class MensagemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'remetente_id' => User::factory(),
            'destinatario_id' => User::factory(),
            'conteudo' => fake()->sentence(),
        ];
    }
}
