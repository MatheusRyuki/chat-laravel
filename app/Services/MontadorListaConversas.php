<?php

namespace App\Services;

use App\Enums\TipoConversa;
use App\Models\Conversa;
use App\Models\User;
use Illuminate\Support\Collection;

class MontadorListaConversas
{
    public function __construct(
        private ServicoLeitura $leituras,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function montar(User $usuario, ?int $contatoIdAtivo, ?int $grupoIdAtivo): Collection
    {
        $contatos = User::query()
            ->whereKeyNot($usuario->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'email']);

        $conversasIndividuais = Conversa::query()
            ->where('tipo', TipoConversa::Individual)
            ->whereHas('participantesAtivos', fn ($consulta) => $consulta->where('user_id', $usuario->id))
            ->with([
                'ultimaMensagem',
                'participantesAtivos.user',
            ])
            ->get();

        $individuaisPorContato = $conversasIndividuais->keyBy(
            fn (Conversa $conversa): int => (int) ($conversa->outroParticipante($usuario)?->id ?? 0)
        );

        $grupos = Conversa::query()
            ->where('tipo', TipoConversa::Grupo)
            ->whereHas('participantesAtivos', fn ($consulta) => $consulta->where('user_id', $usuario->id))
            ->with(['ultimaMensagem', 'participantesAtivos.user'])
            ->get();

        $conversaIds = $individuaisPorContato->pluck('id')
            ->concat($grupos->pluck('id'))
            ->filter()
            ->values()
            ->all();

        $naoLidas = $this->leituras->contarNaoLidas($usuario, $conversaIds);

        $itens = $contatos->map(function (User $contato) use ($individuaisPorContato, $naoLidas, $contatoIdAtivo): array {
            $conversa = $individuaisPorContato->get($contato->id);
            $ultima = $conversa?->ultimaMensagem;

            return [
                'tipo' => 'individual',
                'conversa_id' => $conversa?->id,
                'contato_id' => $contato->id,
                'nome' => $contato->name,
                'email' => $contato->email,
                'previa' => previa_mensagem($ultima),
                'nao_lidas' => $conversa ? (int) ($naoLidas[$conversa->id] ?? 0) : 0,
                'ultima_em' => $conversa?->ultima_mensagem_em,
                'ultima_id' => $conversa?->ultima_mensagem_id,
                'ativa' => $contatoIdAtivo !== null && (int) $contatoIdAtivo === (int) $contato->id,
                'url' => route('dashboard', ['contato' => $contato->id]),
                'presenca_usuario_id' => $contato->id,
                'versao' => (int) ($conversa?->versao ?? 0),
            ];
        });

        $itensGrupo = $grupos->map(function (Conversa $grupo) use ($naoLidas, $grupoIdAtivo): array {
            return [
                'tipo' => 'grupo',
                'conversa_id' => $grupo->id,
                'contato_id' => null,
                'nome' => (string) $grupo->nome,
                'email' => null,
                'previa' => previa_mensagem($grupo->ultimaMensagem),
                'nao_lidas' => (int) ($naoLidas[$grupo->id] ?? 0),
                'ultima_em' => $grupo->ultima_mensagem_em,
                'ultima_id' => $grupo->ultima_mensagem_id,
                'ativa' => $grupoIdAtivo !== null && (int) $grupoIdAtivo === (int) $grupo->id,
                'url' => route('dashboard', ['grupo' => $grupo->id]),
                'presenca_usuario_id' => null,
                'versao' => (int) $grupo->versao,
            ];
        });

        return $itens->concat($itensGrupo)
            ->sort(function (array $a, array $b): int {
                $tempoA = $a['ultima_em']?->getTimestamp() ?? 0;
                $tempoB = $b['ultima_em']?->getTimestamp() ?? 0;

                if ($tempoA !== $tempoB) {
                    return $tempoB <=> $tempoA;
                }

                $idA = (int) ($a['ultima_id'] ?? 0);
                $idB = (int) ($b['ultima_id'] ?? 0);

                if ($idA !== $idB) {
                    return $idB <=> $idA;
                }

                $nome = strcmp($a['nome'], $b['nome']);

                if ($nome !== 0) {
                    return $nome;
                }

                return ((int) ($a['conversa_id'] ?? $a['contato_id'] ?? 0))
                    <=> ((int) ($b['conversa_id'] ?? $b['contato_id'] ?? 0));
            })
            ->values();
    }
}
