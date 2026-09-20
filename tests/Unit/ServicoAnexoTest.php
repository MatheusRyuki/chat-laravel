<?php

namespace Tests\Unit;

use App\Services\ServicoAnexo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ServicoAnexoTest extends TestCase
{
    public function test_gravacao_recusada_nao_retorna_metadados_de_arquivo_inexistente(): void
    {
        $arquivo = Mockery::mock(UploadedFile::class);
        $arquivo->shouldReceive('getMimeType')->once()->andReturn('image/png');
        $arquivo->shouldReceive('storeAs')->once()->andReturn(false);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Não foi possível armazenar o anexo.');
        (new ServicoAnexo)->guardar($arquivo);
    }

    public function test_formato_nao_permitido_e_recusado_antes_da_gravacao(): void
    {
        $arquivo = Mockery::mock(UploadedFile::class);
        $arquivo->shouldReceive('getMimeType')->once()->andReturn('image/svg+xml');
        $arquivo->shouldNotReceive('storeAs');
        $this->expectException(\InvalidArgumentException::class);
        (new ServicoAnexo)->guardar($arquivo);
    }

    public function test_remocao_ausente_e_idempotente(): void
    {
        Storage::fake('anexos');
        $servico = new ServicoAnexo;
        $servico->excluir(null);
        $servico->excluir('ausente.png');
        $this->assertSame([], Storage::disk('anexos')->allFiles());
    }

    public function test_falha_de_remocao_e_registrada_sem_interromper_o_fluxo(): void
    {
        Storage::shouldReceive('disk')->with('anexos')->once()->andThrow(new \RuntimeException('disco indisponível'));
        Log::shouldReceive('warning')->once()->with('Falha ao remover anexo físico.', Mockery::on(fn ($dados) => $dados['caminho'] === 'foto.png' && $dados['erro'] === 'disco indisponível'));
        (new ServicoAnexo)->excluir('foto.png');
    }
}
