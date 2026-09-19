<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegistrarLeituraRequest;
use App\Models\Conversa;
use App\Services\ServicoLeitura;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class LeituraController extends Controller
{
    public function __construct(private ServicoLeitura $leituras) {}

    public function store(RegistrarLeituraRequest $request): JsonResponse
    {
        $conversa = Conversa::query()->findOrFail((int) $request->validated('conversa_id'));

        Gate::authorize('view', $conversa);

        $this->leituras->registrar(
            $request->user(),
            $conversa,
            (int) $request->validated('ate_id'),
        );

        return response()->json([
            'nao_lidas' => $this->leituras->quantidade($request->user(), $conversa),
            'ate_id' => (int) $request->validated('ate_id'),
        ]);
    }
}
