<?php

namespace App\Http\Controllers;

use App\Http\Requests\BloquearContatoRequest;
use App\Models\Bloqueio;
use App\Models\User;
use App\Services\ServicoBloqueio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BloqueioController extends Controller
{
    public function __construct(private ServicoBloqueio $bloqueios) {}

    public function store(BloquearContatoRequest $request): RedirectResponse
    {
        $alvo = User::query()->findOrFail((int) $request->validated('user_id'));

        $this->bloqueios->bloquear($request->user(), $alvo);

        return redirect()
            ->back()
            ->with('status', $alvo->name.' foi bloqueado. O histórico anterior foi preservado. O bloqueio individual não oculta mensagens em grupos compartilhados.');
    }

    public function destroy(Request $request, Bloqueio $bloqueio): RedirectResponse
    {
        Gate::authorize('delete', $bloqueio);

        $nome = $bloqueio->bloqueado?->name ?? 'contato';
        $this->bloqueios->desbloquear($request->user(), $bloqueio->bloqueado);

        return redirect()
            ->back()
            ->with('status', $nome.' foi desbloqueado.');
    }
}
