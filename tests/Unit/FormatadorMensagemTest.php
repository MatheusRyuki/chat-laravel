<?php

namespace Tests\Unit;

use App\Support\FormatadorMensagem;
use PHPUnit\Framework\TestCase;

class FormatadorMensagemTest extends TestCase
{
    public function test_converte_apenas_http_e_https_e_escapa_o_restante(): void
    {
        $html = FormatadorMensagem::paraHtml("Veja https://exemplo.com/a e javascript:alert(1)\n<script>x</script>");

        $this->assertStringContainsString('href="https://exemplo.com/a"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('javascript:alert(1)', $html);
        $this->assertStringContainsString('&lt;script&gt;x&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>x</script>', $html);
    }
}
