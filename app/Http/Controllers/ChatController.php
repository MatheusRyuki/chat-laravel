<?php

namespace App\Http\Controllers;

use App\Models\Mensagem;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ChatController extends Controller
{
    public function __invoke(Request $request): View
    {
        $usuario = $request->user();

        $contatos = User::query()
            ->whereKeyNot($usuario->id)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $contatoId = $request->query('contato');
        $selecionado = null;
        $mensagens = collect();

        if ($contatoId !== null && $contatoId !== '') {
            $selecionado = $contatos->firstWhere('id', (int) $contatoId);

            if ($selecionado === null) {
                abort(404);
            }

            $mensagens = Mensagem::query()
                ->entre($usuario, $selecionado)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();
        }

        return view('chat.index', [
            'contatos' => $contatos,
            'selecionado' => $selecionado,
            'mensagens' => $mensagens,
        ]);
    }
}
