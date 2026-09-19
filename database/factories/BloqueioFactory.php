<?php

namespace Database\Factories;

use App\Models\Bloqueio;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bloqueio>
 */
class BloqueioFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bloqueador_id' => User::factory(),
            'bloqueado_id' => User::factory(),
        ];
    }
}
