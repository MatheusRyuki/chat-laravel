<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversas', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 20);
            $table->string('nome')->nullable();
            $table->string('chave_par', 32)->nullable()->unique();
            $table->foreignId('criador_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('versao')->default(0);
            $table->foreignId('ultima_mensagem_id')->nullable();
            $table->timestamp('ultima_mensagem_em')->nullable();
            $table->timestamps();

            $table->index(['tipo', 'ultima_mensagem_em']);
        });

        Schema::create('conversa_participantes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversa_id')->constrained('conversas')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('papel', 20);
            $table->unsignedBigInteger('ultima_leitura_mensagem_id')->nullable();
            $table->timestamp('removido_em')->nullable();
            $table->timestamps();

            $table->unique(['conversa_id', 'user_id']);
            $table->index(['user_id', 'removido_em']);
        });

        Schema::create('bloqueios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bloqueador_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('bloqueado_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['bloqueador_id', 'bloqueado_id']);
        });

        Schema::table('mensagens', function (Blueprint $table) {
            $table->foreignId('conversa_id')->nullable()->after('id')->constrained('conversas')->cascadeOnDelete();
            $table->unsignedBigInteger('versao')->default(1)->after('conteudo');
            $table->timestamp('editada_em')->nullable()->after('versao');
            $table->timestamp('removida_em')->nullable()->after('editada_em');
            $table->string('anexo_caminho')->nullable()->after('removida_em');
            $table->string('anexo_mime', 100)->nullable()->after('anexo_caminho');
            $table->unsignedInteger('anexo_tamanho')->nullable()->after('anexo_mime');
            $table->index(['conversa_id', 'created_at', 'id']);
            $table->index(['conversa_id', 'versao']);
        });

        $this->migrarMensagensExistentes();
    }

    public function down(): void
    {
        Schema::table('mensagens', function (Blueprint $table) {
            $table->dropIndex(['conversa_id', 'created_at', 'id']);
            $table->dropIndex(['conversa_id', 'versao']);
            $table->dropConstrainedForeignId('conversa_id');
            $table->dropColumn([
                'versao',
                'editada_em',
                'removida_em',
                'anexo_caminho',
                'anexo_mime',
                'anexo_tamanho',
            ]);
        });

        Schema::dropIfExists('bloqueios');
        Schema::dropIfExists('conversa_participantes');
        Schema::dropIfExists('conversas');
    }

    private function migrarMensagensExistentes(): void
    {
        $mensagens = DB::table('mensagens')
            ->orderBy('id')
            ->get(['id', 'remetente_id', 'destinatario_id', 'created_at']);

        if ($mensagens->isEmpty()) {
            return;
        }

        $conversasPorPar = [];

        foreach ($mensagens as $mensagem) {
            $min = min((int) $mensagem->remetente_id, (int) $mensagem->destinatario_id);
            $max = max((int) $mensagem->remetente_id, (int) $mensagem->destinatario_id);
            $chave = $min.':'.$max;

            if (! isset($conversasPorPar[$chave])) {
                $agora = now();
                $conversaId = DB::table('conversas')->insertGetId([
                    'tipo' => 'individual',
                    'nome' => null,
                    'chave_par' => $chave,
                    'criador_id' => $min,
                    'versao' => 0,
                    'ultima_mensagem_id' => null,
                    'ultima_mensagem_em' => null,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ]);

                DB::table('conversa_participantes')->insert([
                    [
                        'conversa_id' => $conversaId,
                        'user_id' => $min,
                        'papel' => 'membro',
                        'created_at' => $agora,
                        'updated_at' => $agora,
                    ],
                    [
                        'conversa_id' => $conversaId,
                        'user_id' => $max,
                        'papel' => 'membro',
                        'created_at' => $agora,
                        'updated_at' => $agora,
                    ],
                ]);

                $conversasPorPar[$chave] = $conversaId;
            }

            DB::table('mensagens')->where('id', $mensagem->id)->update([
                'conversa_id' => $conversasPorPar[$chave],
                'versao' => $mensagem->id,
            ]);
        }

        foreach ($conversasPorPar as $conversaId) {
            $ultima = DB::table('mensagens')
                ->where('conversa_id', $conversaId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first(['id', 'created_at', 'versao']);

            if ($ultima === null) {
                continue;
            }

            DB::table('conversas')->where('id', $conversaId)->update([
                'ultima_mensagem_id' => $ultima->id,
                'ultima_mensagem_em' => $ultima->created_at,
                'versao' => $ultima->versao,
                'updated_at' => now(),
            ]);
        }
    }
};
