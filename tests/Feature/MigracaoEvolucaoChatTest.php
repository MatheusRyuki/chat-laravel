<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MigracaoEvolucaoChatTest extends TestCase
{
    private bool $precisaRestaurarEsquema = false;

    protected function tearDown(): void
    {
        if ($this->precisaRestaurarEsquema) {
            $this->artisan('migrate:fresh', [
                '--force' => true,
                '--no-interaction' => true,
            ]);
        }

        parent::tearDown();
    }

    public function test_atualizacao_incremental_preserva_id_conteudo_e_data_das_mensagens(): void
    {
        $this->precisaRestaurarEsquema = true;
        $this->artisan('migrate:fresh', [
            '--path' => [
                'database/migrations/0001_01_01_000000_create_users_table.php',
                'database/migrations/0001_01_01_000001_create_cache_table.php',
                'database/migrations/0001_01_01_000002_create_jobs_table.php',
                'database/migrations/2026_09_19_133912_create_mensagens_table.php',
            ],
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('conversas'));
        $this->assertFalse(Schema::hasColumn('mensagens', 'conversa_id'));

        $agora = '2026-01-15 10:20:30';
        $ana = DB::table('users')->insertGetId([
            'name' => 'Ana Migracao',
            'email' => 'ana.migracao@example.com',
            'password' => 'x',
            'created_at' => $agora,
            'updated_at' => $agora,
        ]);
        $bruno = DB::table('users')->insertGetId([
            'name' => 'Bruno Migracao',
            'email' => 'bruno.migracao@example.com',
            'password' => 'x',
            'created_at' => $agora,
            'updated_at' => $agora,
        ]);
        $mensagemId = DB::table('mensagens')->insertGetId([
            'remetente_id' => $ana,
            'destinatario_id' => $bruno,
            'conteudo' => 'Texto conhecido da migracao incremental',
            'created_at' => $agora,
            'updated_at' => $agora,
        ]);

        $this->artisan('migrate', [
            '--path' => [
                'database/migrations/2026_09_19_173342_create_conversas_e_evolucao_do_chat_tables.php',
                'database/migrations/2026_09_19_174921_make_destinatario_id_nullable_on_mensagens.php',
            ],
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $mensagem = DB::table('mensagens')->where('id', $mensagemId)->first();

        $this->assertNotNull($mensagem);
        $this->assertSame('Texto conhecido da migracao incremental', $mensagem->conteudo);
        $this->assertSame($ana, (int) $mensagem->remetente_id);
        $this->assertSame($bruno, (int) $mensagem->destinatario_id);
        $this->assertNotNull($mensagem->conversa_id);
        $this->assertSame($agora, $mensagem->created_at);

        $conversa = DB::table('conversas')->where('id', $mensagem->conversa_id)->first();
        $this->assertSame('individual', $conversa->tipo);
        $this->assertSame($mensagemId, (int) $conversa->ultima_mensagem_id);
    }

    public function test_instalacao_limpa_cria_tabelas_da_evolucao(): void
    {
        $this->artisan('migrate:fresh', [
            '--force' => true,
            '--no-interaction' => true,
        ])->assertSuccessful();

        $this->assertTrue(Schema::hasTable('conversas'));
        $this->assertTrue(Schema::hasTable('conversa_participantes'));
        $this->assertTrue(Schema::hasTable('bloqueios'));
        $this->assertTrue(Schema::hasColumn('mensagens', 'conversa_id'));
        $this->assertTrue(Schema::hasColumn('mensagens', 'versao'));
        $this->assertTrue(Schema::hasColumn('mensagens', 'anexo_caminho'));
    }
}
