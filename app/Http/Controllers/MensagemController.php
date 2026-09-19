<?php

namespace App\Http\Controllers;

use App\Broadcasting\PublicadorMensagem;
use App\Http\Requests\EnviarMensagemRequest;
use App\Models\Mensagem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MensagemController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $usuario = $request->user();
        $contatoId = $request->query('contato');

        if ($contatoId === null || $contatoId === '') {
            abort(404);
        }

        $contato = User::query()
            ->whereKeyNot($usuario->id)
            ->whereKey((int) $contatoId)
            ->first();

        if ($contato === null) {
            abort(404);
        }

        $mensagens = Mensagem::query()
            ->entre($usuario, $contato)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Mensagem $mensagem) => $mensagem->paraBroadcast())
            ->values();

        return response()->json([
            'mensagens' => $mensagens,
        ]);
    }

    public function store(EnviarMensagemRequest $request, PublicadorMensagem $publicador): RedirectResponse
    {
        $dados = $request->validated();

        $mensagem = Mensagem::query()->create([
            'remetente_id' => $request->user()->id,
            'destinatario_id' => $dados['destinatario_id'],
            'conteudo' => $dados['conteudo'],
        ]);

        $publicador->publicar($mensagem);

        return redirect()->route('dashboard', [
            'contato' => $dados['destinatario_id'],
        ]);
    }
}
