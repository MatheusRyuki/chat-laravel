<?php

namespace App\Http\Controllers;

use App\Broadcasting\PublicadorMensagem;
use App\Http\Requests\AtualizarMensagemRequest;
use App\Http\Requests\EnviarMensagemRequest;
use App\Models\Conversa;
use App\Models\Mensagem;
use App\Models\User;
use App\Services\ServicoAnexo;
use App\Services\ServicoConversa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class MensagemController extends Controller
{
    public function __construct(
        private PublicadorMensagem $publicador,
        private ServicoAnexo $anexos,
        private ServicoConversa $conversas,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $conversa = $this->resolverConversa($request);

        if ($conversa === null) {
            return response()->json([
                'mensagens' => [],
                'tem_anteriores' => false,
                'versao' => 0,
            ]);
        }

        Gate::authorize('view', $conversa);

        $limite = (int) config('chat.historico_janela');
        $antesId = $request->query('antes_id');
        $depoisId = $request->query('depois_id');
        $versao = $request->query('versao');

        $consulta = Mensagem::query()
            ->with('remetente')
            ->where('conversa_id', $conversa->id);

        if ($versao !== null && $versao !== '') {
            $alteradas = (clone $consulta)
                ->where('versao', '>', (int) $versao)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn (Mensagem $mensagem) => $mensagem->paraBroadcast())
                ->values();

            return response()->json([
                'mensagens' => $alteradas,
                'versao' => (int) $conversa->fresh()?->versao,
                'tem_anteriores' => false,
            ]);
        }

        if ($antesId !== null && $antesId !== '') {
            $cursor = Mensagem::query()
                ->where('conversa_id', $conversa->id)
                ->whereKey((int) $antesId)
                ->first();

            if ($cursor === null) {
                abort(404);
            }

            $anteriores = Mensagem::query()
                ->with('remetente')
                ->where('conversa_id', $conversa->id)
                ->where(function ($filtro) use ($cursor): void {
                    $filtro->where('created_at', '<', $cursor->created_at)
                        ->orWhere(function ($empate) use ($cursor): void {
                            $empate->where('created_at', $cursor->created_at)
                                ->where('id', '<', $cursor->id);
                        });
                })
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit($limite + 1)
                ->get();

            $temAnteriores = $anteriores->count() > $limite;
            $pagina = $anteriores->take($limite)->reverse()->values();

            return response()->json([
                'mensagens' => $pagina->map(fn (Mensagem $mensagem) => $mensagem->paraBroadcast())->values(),
                'tem_anteriores' => $temAnteriores,
                'versao' => (int) $conversa->versao,
            ]);
        }

        $recentes = $consulta
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limite + 1)
            ->get();

        $temAnteriores = $recentes->count() > $limite;
        $mensagens = $recentes->take($limite)->reverse()->values();

        if ($depoisId !== null && $depoisId !== '') {
            $mensagens = Mensagem::query()
                ->with('remetente')
                ->where('conversa_id', $conversa->id)
                ->where('id', '>', (int) $depoisId)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();
            $temAnteriores = false;
        }

        return response()->json([
            'mensagens' => $mensagens->map(fn (Mensagem $mensagem) => $mensagem->paraBroadcast())->values(),
            'tem_anteriores' => $temAnteriores,
            'versao' => (int) $conversa->versao,
        ]);
    }

    public function store(EnviarMensagemRequest $request): RedirectResponse|JsonResponse
    {
        $conversa = $this->resolverConversaParaEnvio($request);

        Gate::authorize('enviar', $conversa);

        $arquivo = $request->file('anexo');
        $meta = null;

        if ($arquivo !== null) {
            $meta = $this->anexos->guardar($arquivo);
        }

        try {
            $mensagem = DB::transaction(function () use ($request, $conversa, $meta): Mensagem {
                $versao = $conversa->proximaVersao();
                $destinatarioId = $conversa->eIndividual()
                    ? $conversa->idsParticipantesAtivos()->first(fn (int $id): bool => $id !== (int) $request->user()->id)
                    : null;

                $mensagem = Mensagem::query()->create([
                    'conversa_id' => $conversa->id,
                    'remetente_id' => $request->user()->id,
                    'destinatario_id' => $destinatarioId,
                    'conteudo' => (string) $request->input('conteudo', ''),
                    'versao' => $versao,
                    'anexo_caminho' => $meta['caminho'] ?? null,
                    'anexo_mime' => $meta['mime'] ?? null,
                    'anexo_tamanho' => $meta['tamanho'] ?? null,
                ]);

                $conversa->registrarEnvio($mensagem);

                return $mensagem->load('remetente');
            });
        } catch (Throwable $e) {
            if ($meta !== null) {
                $this->anexos->excluir($meta['caminho']);
            }

            throw $e;
        }

        $this->publicador->publicar($mensagem);

        if ($request->expectsJson()) {
            return response()->json([
                'mensagem' => $mensagem->paraBroadcast(),
            ], Response::HTTP_CREATED);
        }

        return $this->redirecionarConversa($conversa);
    }

    public function update(AtualizarMensagemRequest $request, Mensagem $mensagem): JsonResponse|RedirectResponse
    {
        $conversa = $mensagem->conversa;
        abort_if($conversa === null, 404);

        DB::transaction(function () use ($request, $mensagem, $conversa): void {
            $mensagem->forceFill([
                'conteudo' => $request->validated('conteudo'),
                'editada_em' => now(),
                'versao' => $conversa->proximaVersao(),
            ])->save();
        });

        $mensagem->refresh()->load('remetente');
        $this->publicador->publicarAlteracao($mensagem);

        if ($request->expectsJson()) {
            return response()->json([
                'mensagem' => $mensagem->paraBroadcast(),
            ]);
        }

        return $this->redirecionarConversa($conversa);
    }

    public function confirmacaoRemocao(Mensagem $mensagem): View
    {
        Gate::authorize('delete', $mensagem);

        $conversa = $mensagem->conversa;
        abort_if($conversa === null, 404);

        return view('chat.confirmacao-remocao', [
            'mensagem' => $mensagem,
            'voltarUrl' => $this->urlConversa($conversa),
        ]);
    }

    public function destroy(Request $request, Mensagem $mensagem): JsonResponse|RedirectResponse
    {
        Gate::authorize('delete', $mensagem);

        $conversa = $mensagem->conversa;
        abort_if($conversa === null, 404);

        $caminho = $mensagem->anexo_caminho;

        DB::transaction(function () use ($mensagem, $conversa): void {
            $mensagem->forceFill([
                'removida_em' => now(),
                'conteudo' => '',
                'versao' => $conversa->proximaVersao(),
            ])->save();
        });

        if (filled($caminho)) {
            $this->anexos->excluir($caminho);
        }

        $mensagem->refresh()->load('remetente');
        $this->publicador->publicarAlteracao($mensagem);

        if ($request->expectsJson()) {
            return response()->json([
                'mensagem' => $mensagem->paraBroadcast(),
            ]);
        }

        return $this->redirecionarConversa($conversa);
    }

    private function resolverConversaParaEnvio(EnviarMensagemRequest $request): Conversa
    {
        if ($request->filled('conversa_id')) {
            return Conversa::query()->findOrFail((int) $request->input('conversa_id'));
        }

        $destinatario = User::query()->findOrFail((int) $request->input('destinatario_id'));

        return $this->conversas->individualEntre($request->user(), $destinatario);
    }

    private function resolverConversa(Request $request): ?Conversa
    {
        $usuario = $request->user();

        if ($request->filled('conversa')) {
            return Conversa::query()->findOrFail((int) $request->query('conversa'));
        }

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

        $chave = min((int) $usuario->id, (int) $contato->id).':'.max((int) $usuario->id, (int) $contato->id);

        return Conversa::query()->where('chave_par', $chave)->first();
    }

    private function redirecionarConversa(Conversa $conversa): RedirectResponse
    {
        return redirect()->to($this->urlConversa($conversa));
    }

    private function urlConversa(Conversa $conversa): string
    {
        if ($conversa->eGrupo()) {
            return route('dashboard', ['grupo' => $conversa->id]);
        }

        $destinatarioId = $conversa->idsParticipantesAtivos()
            ->first(fn (int $id): bool => $id !== (int) request()->user()->id);

        return route('dashboard', [
            'contato' => $destinatarioId,
        ]);
    }
}
