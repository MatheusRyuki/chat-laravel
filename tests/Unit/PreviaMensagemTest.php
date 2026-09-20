<?php

namespace Tests\Unit;

use App\Models\Mensagem;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PreviaMensagemTest extends TestCase
{
    public static function previas(): array
    {
        return [
            'sem mensagem' => [null, 'Nenhuma mensagem ainda'],
            'texto vazio' => [['conteudo' => '  '], 'Nenhuma mensagem ainda'],
            'texto curto' => [['conteudo' => ' Olá '], 'Olá'],
            'texto longo unicode' => [['conteudo' => str_repeat('á', 81)], str_repeat('á', 77).'…'],
            'imagem' => [['anexo_caminho' => 'foto.png'], 'Imagem'],
            'imagem com legenda' => [['anexo_caminho' => 'foto.png', 'conteudo' => 'Oi'], 'Imagem · Oi'],
            'legenda longa' => [['anexo_caminho' => 'foto.png', 'conteudo' => str_repeat('é', 61)], 'Imagem · '.str_repeat('é', 57).'…'],
            'removida oculta conteudo' => [['conteudo' => 'segredo', 'anexo_caminho' => 'foto.png', 'removida_em' => '2026-01-01'], 'Mensagem removida'],
        ];
    }

    #[DataProvider('previas')]
    public function test_previa_respeita_conteudo_e_estado(?array $atributos, string $esperado): void
    {
        $this->assertSame($esperado, previa_mensagem($atributos === null ? null : new Mensagem($atributos)));
    }

    public function test_avatar_escapa_iniciais_e_preserva_unicode(): void
    {
        $svg = rawurldecode(explode(',', avatar_data_uri(' < & '), 2)[1]);
        $this->assertStringContainsString('&lt;&amp;', $svg);
        $this->assertStringNotContainsString('><&<', $svg);
        $this->assertStringContainsString('ÉÁ', rawurldecode(avatar_data_uri('  élio   álvares ')));
    }
}
