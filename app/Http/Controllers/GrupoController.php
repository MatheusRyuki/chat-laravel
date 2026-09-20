<?php

namespace App\Http\Controllers;

use App\Broadcasting\PublicadorMensagem;
use App\Http\Requests\CriarGrupoRequest;
use App\Http\Requests\GerenciarMembroGrupoRequest;
use App\Models\Conversa;
use App\Models\User;
use App\Services\ServicoConversa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class GrupoController extends Controller
{
    public function __construct(
        private ServicoConversa $conversas,
        private PublicadorMensagem $publicador,
    ) {}

    public function store(CriarGrupoRequest $request): RedirectResponse|JsonResponse
    {
        $ids = collect($request->validated('membros'))->map(fn ($id): int => (int) $id);
        $membros = User::query()->whereIn('id', $ids)->get();

        $conversa = $this->conversas->criarGrupo(
            $request->user(),
            $request->validated('nome'),
            $membros,
        );

        $this->publicador->publicarParticipante(
            $conversa,
            $request->user(),
            'criado',
            $conversa->idsParticipantesAtivos()->all(),
        );

        $aviso = 'Grupo criado. Novos membros podem consultar o histórico do grupo.';

        if ($request->expectsJson()) {
            return response()->json([
                'conversa_id' => $conversa->id,
                'mensagem' => $aviso,
            ], 201);
        }

        return redirect()
            ->route('dashboard', ['grupo' => $conversa->id])
            ->with('status', $aviso);
    }

    public function adicionarMembro(GerenciarMembroGrupoRequest $request, Conversa $conversa): RedirectResponse|JsonResponse
    {
        Gate::authorize('gerenciarMembros', $conversa);

        $usuario = User::query()->findOrFail((int) $request->validated('user_id'));
        $this->conversas->adicionarMembro($conversa, $usuario);
        $conversa->refresh()->load('participantesAtivos.user');

        $ids = $conversa->idsParticipantesAtivos()->all();

        $this->publicador->publicarParticipante($conversa, $usuario, 'adicionado', $ids);

        $aviso = $usuario->name.' entrou no grupo e pode consultar o histórico.';

        if ($request->expectsJson()) {
            return response()->json(['mensagem' => $aviso]);
        }

        return redirect()
            ->route('dashboard', ['grupo' => $conversa->id, 'gerenciar_membros' => 1])
            ->with('status', $aviso);
    }

    public function removerMembro(Request $request, Conversa $conversa, User $user): RedirectResponse|JsonResponse
    {
        Gate::authorize('gerenciarMembros', $conversa);

        abort_if((int) $user->id === (int) $request->user()->id, 422);

        $idsAntes = $conversa->idsParticipantesAtivos()->all();
        $this->conversas->removerMembro($conversa, $user);

        $this->publicador->publicarParticipante(
            $conversa,
            $user,
            'removido',
            array_values(array_unique([...$idsAntes, (int) $user->id])),
        );

        if ($request->expectsJson()) {
            return response()->json(['mensagem' => $user->name.' foi removido do grupo.']);
        }

        return redirect()->route('dashboard', ['grupo' => $conversa->id, 'gerenciar_membros' => 1]);
    }
}
