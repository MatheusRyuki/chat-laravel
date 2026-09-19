<?php

namespace Database\Factories;

use App\Enums\TipoConversa;
use App\Models\Conversa;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversa>
 */
class ConversaFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tipo' => TipoConversa::Grupo,
            'nome' => fake()->words(3, true),
            'chave_par' => null,
            'criador_id' => User::factory(),
            'versao' => 0,
        ];
    }

    public function individual(): static
    {
        return $this->state(fn (): array => [
            'tipo' => TipoConversa::Individual,
            'nome' => null,
        ]);
    }
}
