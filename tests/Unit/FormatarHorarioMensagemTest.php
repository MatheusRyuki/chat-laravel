<?php

namespace Tests\Unit;

use Illuminate\Support\Carbon;
use Tests\TestCase;

class FormatarHorarioMensagemTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_formata_hoje_mesmo_ano_e_outro_ano_no_fuso_da_aplicacao(): void
    {
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::parse('2026-09-19 15:30:00', 'UTC'));

        $this->assertSame('14:05', formatar_horario_mensagem(Carbon::parse('2026-09-19 14:05:00', 'UTC')));
        $this->assertSame('18/03, 09:00', formatar_horario_mensagem(Carbon::parse('2026-03-18 09:00:00', 'UTC')));
        $this->assertSame('31/12/2025, 23:59', formatar_horario_mensagem(Carbon::parse('2025-12-31 23:59:00', 'UTC')));
        $this->assertSame('', formatar_horario_mensagem(null));
    }
}
