<?php

namespace App\Http\Controllers;

use App\Broadcasting\PublicadorMensagem;
use App\Http\Requests\RegistrarDigitacaoRequest;
use App\Models\Conversa;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

class DigitacaoController extends Controller
{
    public function __construct(private PublicadorMensagem $publicador) {}

    public function store(RegistrarDigitacaoRequest $request): Response
    {
        $conversa = Conversa::query()->findOrFail((int) $request->validated('conversa_id'));

        Gate::authorize('digitar', $conversa);

        $digitando = $request->boolean('digitando', true);
        $chave = 'digitacao:'.$request->user()->id.':'.$conversa->id;
        $intervalo = (int) config('chat.digitacao_intervalo_segundos', 2);

        if ($digitando && ! Cache::add($chave, 1, $intervalo)) {
            return response()->noContent();
        }

        if (! $digitando) {
            Cache::forget($chave);
        }

        $this->publicador->publicarDigitacao($conversa, $request->user(), $digitando);

        return response()->noContent();
    }
}
