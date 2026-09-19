<?php

namespace App\Http\Controllers;

use App\Models\Mensagem;
use App\Services\ServicoAnexo;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AnexoController extends Controller
{
    public function __construct(private ServicoAnexo $anexos) {}

    public function show(Mensagem $mensagem): StreamedResponse
    {
        Gate::authorize('verAnexo', $mensagem);

        $caminho = $mensagem->anexo_caminho;

        abort_if(! filled($caminho) || ! $this->anexos->existe($caminho), 404);

        $absoluto = $this->anexos->caminhoAbsoluto($caminho);
        $mime = $mensagem->anexo_mime ?: 'application/octet-stream';

        return response()->stream(function () use ($absoluto): void {
            $fluxo = fopen($absoluto, 'rb');

            if ($fluxo === false) {
                return;
            }

            fpassthru($fluxo);
            fclose($fluxo);
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="imagem"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
