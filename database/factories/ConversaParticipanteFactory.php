<?php

namespace Database\Factories;

use App\Enums\PapelParticipante;
use App\Models\Conversa;
use App\Models\ConversaParticipante;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ConversaParticipante>
 */
class ConversaParticipanteFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'conversa_id' => Conversa::factory(),
            'user_id' => User::factory(),
            'papel' => PapelParticipante::Membro,
        ];
    }
}
