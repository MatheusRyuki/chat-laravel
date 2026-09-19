<?php

use App\Http\Controllers\AnexoController;
use App\Http\Controllers\BloqueioController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DigitacaoController;
use App\Http\Controllers\GrupoController;
use App\Http\Controllers\LeituraController;
use App\Http\Controllers\MensagemController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::get('/', ChatController::class)
    ->middleware(['auth'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/mensagens', [MensagemController::class, 'index'])->name('mensagens.index');
    Route::post('/mensagens', [MensagemController::class, 'store'])->name('mensagens.store');
    Route::patch('/mensagens/{mensagem}', [MensagemController::class, 'update'])->name('mensagens.update');
    Route::get('/mensagens/{mensagem}/confirmacao-remocao', [MensagemController::class, 'confirmacaoRemocao'])->name('mensagens.confirmacao-remocao');
    Route::delete('/mensagens/{mensagem}', [MensagemController::class, 'destroy'])->name('mensagens.destroy');
    Route::get('/mensagens/{mensagem}/anexo', [AnexoController::class, 'show'])->name('mensagens.anexo');

    Route::post('/leituras', [LeituraController::class, 'store'])->name('leituras.store');
    Route::post('/digitacao', [DigitacaoController::class, 'store'])->name('digitacao.store');

    Route::post('/grupos', [GrupoController::class, 'store'])->name('grupos.store');
    Route::post('/grupos/{conversa}/membros', [GrupoController::class, 'adicionarMembro'])->name('grupos.membros.store');
    Route::delete('/grupos/{conversa}/membros/{user}', [GrupoController::class, 'removerMembro'])->name('grupos.membros.destroy');

    Route::post('/bloqueios', [BloqueioController::class, 'store'])->name('bloqueios.store');
    Route::delete('/bloqueios/{bloqueio}', [BloqueioController::class, 'destroy'])->name('bloqueios.destroy');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

if (config('chat.e2e')) {
    Route::get('/e2e/diagnostico', function () {
        return response()->json([
            'broadcast' => config('broadcasting.default'),
            'prefixo_canal' => prefixo_canal_broadcast(),
            'pusher_key_configurada' => filled(config('broadcasting.connections.pusher.key')),
            'pusher_cluster' => config('broadcasting.connections.pusher.options.cluster'),
            'session_driver' => config('session.driver'),
            'php_cli_server_workers' => getenv('PHP_CLI_SERVER_WORKERS') ?: null,
        ]);
    })->name('e2e.diagnostico');

    Route::get('/e2e/atrasar', function () {
        usleep(1_500_000);

        return response()->json(['ok' => true]);
    })->name('e2e.atrasar');
}
