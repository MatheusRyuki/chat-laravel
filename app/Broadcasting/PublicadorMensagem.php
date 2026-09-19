<?php

namespace App\Broadcasting;

use App\Events\MensagemAlterada;
use App\Events\MensagemEnviada;
use App\Events\ParticipanteAtualizado;
use App\Events\ParticipanteDigitando;
use App\Models\Conversa;
use App\Models\Mensagem;
use App\Models\User;
use App\Services\ServicoBloqueio;
use App\Services\ServicoConversa;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublicadorMensagem
{
    public function __construct(
        private ServicoConversa $conversas,
        private ServicoBloqueio $bloqueios,
    ) {}

    public function publicar(Mensagem $mensagem): void
    {
        try {
            $this->disparar($mensagem);
        } catch (Throwable $e) {
            Log::warning('Falha ao publicar mensagem no Pusher.', [
                'mensagem_id' => $mensagem->id,
                'tipo' => $e::class,
                'erro' => $e->getMessage(),
            ]);
        }
    }

    public function publicarAlteracao(Mensagem $mensagem): void
    {
        try {
            $ids = $this->idsDestino($mensagem->conversa, $mensagem);

            MensagemAlterada::dispatch($mensagem->paraBroadcast(), $ids);
        } catch (Throwable $e) {
            Log::warning('Falha ao publicar alteração de mensagem no Pusher.', [
                'mensagem_id' => $mensagem->id,
                'tipo' => $e::class,
                'erro' => $e->getMessage(),
            ]);
        }
    }

    public function publicarDigitacao(Conversa $conversa, User $usuario, bool $digitando): void
    {
        try {
            if ($this->bloqueios->conversaIndividualBloqueada($conversa, $usuario)) {
                return;
            }

            $ids = $this->idsDestino($conversa);

            if ($ids === []) {
                return;
            }

            ParticipanteDigitando::dispatch(
                (int) $conversa->id,
                (int) $usuario->id,
                (string) $usuario->name,
                $digitando,
                $ids,
            );
        } catch (Throwable $e) {
            Log::warning('Falha ao publicar digitação no Pusher.', [
                'conversa_id' => $conversa->id,
                'tipo' => $e::class,
                'erro' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<int, int>  $destinatarioIds
     */
    public function publicarParticipante(Conversa $conversa, User $alvo, string $acao, array $destinatarioIds): void
    {
        try {
            ParticipanteAtualizado::dispatch(
                (int) $conversa->id,
                (int) $alvo->id,
                $acao,
                (string) $conversa->nome,
                $destinatarioIds,
            );
        } catch (Throwable $e) {
            Log::warning('Falha ao publicar atualização de participante no Pusher.', [
                'conversa_id' => $conversa->id,
                'tipo' => $e::class,
                'erro' => $e->getMessage(),
            ]);
        }
    }

    protected function disparar(Mensagem $mensagem): void
    {
        $mensagem->loadMissing('remetente', 'conversa.participantesAtivos.user');

        $ids = $this->idsDestino($mensagem->conversa, $mensagem);

        MensagemEnviada::dispatch($mensagem->paraBroadcast(), $ids);
    }

    /**
     * @return array<int, int>
     */
    private function idsDestino(?Conversa $conversa, ?Mensagem $mensagem = null): array
    {
        if ($conversa === null) {
            return [];
        }

        return $this->conversas
            ->idsAutorizadosParaEvento($conversa, $mensagem)
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
