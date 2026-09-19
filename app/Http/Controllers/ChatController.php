<?php

namespace App\Http\Controllers;

use App\Enums\TipoConversa;
use App\Models\Conversa;
use App\Models\Mensagem;
use App\Models\User;
use App\Services\MontadorListaConversas;
use App\Services\ServicoBloqueio;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class ChatController extends Controller
{
    public function __construct(
        private MontadorListaConversas $lista,
        private ServicoBloqueio $bloqueios,
    ) {}

    public function __invoke(Request $request): View
    {
        $usuario = $request->user();
        $usuario->load('bloqueiosFeitos');
        $contatoId = $request->query('contato');
        $grupoId = $request->query('grupo');

        $selecionado = null;
        $conversa = null;
        $mensagens = collect();
        $temAnteriores = false;
        $bloqueadoPorMim = false;
        $bloqueadoPorEle = false;

        if ($contatoId !== null && $contatoId !== '') {
            $selecionado = User::query()
                ->whereKeyNot($usuario->id)
                ->whereKey((int) $contatoId)
                ->first();

            if ($selecionado === null) {
                abort(404);
            }

            $conversa = Conversa::query()
                ->where('tipo', TipoConversa::Individual)
                ->where('chave_par', $this->chavePar($usuario->id, $selecionado->id))
                ->with(['participantesAtivos.user'])
                ->first();

            if ($conversa !== null) {
                [$mensagens, $temAnteriores] = $this->janelaInicial($conversa);
                $bloqueadoPorMim = $this->bloqueios->bloqueioDe($usuario, $selecionado) !== null;
                $bloqueadoPorEle = $this->bloqueios->bloqueioDe($selecionado, $usuario) !== null;
            } else {
                $bloqueadoPorMim = $this->bloqueios->bloqueioDe($usuario, $selecionado) !== null;
                $bloqueadoPorEle = $this->bloqueios->bloqueioDe($selecionado, $usuario) !== null;
            }
        } elseif ($grupoId !== null && $grupoId !== '') {
            $conversa = Conversa::query()
                ->where('tipo', TipoConversa::Grupo)
                ->whereKey((int) $grupoId)
                ->with(['participantesAtivos.user'])
                ->first();

            if ($conversa === null || ! $conversa->usuarioParticipa($usuario)) {
                abort(404);
            }

            [$mensagens, $temAnteriores] = $this->janelaInicial($conversa);
        }

        $itens = $this->lista->montar(
            $usuario,
            $selecionado?->id,
            $conversa?->eGrupo() ? $conversa->id : null,
        );

        $usuariosParaGrupo = User::query()
            ->whereKeyNot($usuario->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('chat.index', [
            'itensLista' => $itens,
            'contatos' => $itens->where('tipo', 'individual')->pluck('nome'),
            'selecionado' => $selecionado,
            'conversa' => $conversa,
            'mensagens' => $mensagens,
            'temAnteriores' => $temAnteriores,
            'bloqueadoPorMim' => $bloqueadoPorMim,
            'bloqueadoPorEle' => $bloqueadoPorEle,
            'usuariosParaGrupo' => $usuariosParaGrupo,
            'janelaHistorico' => (int) config('chat.historico_janela'),
        ]);
    }

    /**
     * @return array{0: Collection<int, Mensagem>, 1: bool}
     */
    private function janelaInicial(Conversa $conversa): array
    {
        $limite = (int) config('chat.historico_janela');

        $recentes = Mensagem::query()
            ->with('remetente')
            ->where('conversa_id', $conversa->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limite + 1)
            ->get();

        $temAnteriores = $recentes->count() > $limite;
        $mensagens = $recentes->take($limite)->reverse()->values();

        return [$mensagens, $temAnteriores];
    }

    private function chavePar(int $um, int $outro): string
    {
        return min($um, $outro).':'.max($um, $outro);
    }
}
