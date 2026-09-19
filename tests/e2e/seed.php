<?php

use App\Models\Mensagem;
use App\Models\User;
use App\Services\ServicoConversa;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::getDriverName() === 'sqlite') {
    DB::statement('PRAGMA journal_mode=WAL;');
}

$senha = Hash::make('password');

$ana = User::query()->create([
    'name' => 'Ana E2E',
    'email' => 'ana.e2e@example.com',
    'password' => $senha,
]);
$bruno = User::query()->create([
    'name' => 'Bruno E2E',
    'email' => 'bruno.e2e@example.com',
    'password' => $senha,
]);
$carla = User::query()->create([
    'name' => 'Carla E2E',
    'email' => 'carla.e2e@example.com',
    'password' => $senha,
]);
User::query()->create([
    'name' => 'Davi E2E',
    'email' => 'davi.e2e@example.com',
    'password' => $senha,
]);

$conversas = $app->make(ServicoConversa::class);
$par = $conversas->individualEntre($ana, $bruno);

foreach (range(1, 55) as $indice) {
    Mensagem::query()->create([
        'conversa_id' => $par->id,
        'remetente_id' => $indice % 2 === 0 ? $bruno->id : $ana->id,
        'destinatario_id' => $indice % 2 === 0 ? $ana->id : $bruno->id,
        'conteudo' => 'Histórico janela '.$indice,
        'versao' => $indice,
        'created_at' => Carbon::parse('2026-09-18 10:00:00')->addMinutes($indice),
        'updated_at' => Carbon::parse('2026-09-18 10:00:00')->addMinutes($indice),
    ]);
}

$ultima = Mensagem::query()->where('conversa_id', $par->id)->orderByDesc('id')->first();
$par->forceFill([
    'versao' => 55,
    'ultima_mensagem_id' => $ultima?->id,
    'ultima_mensagem_em' => $ultima?->created_at,
])->save();

echo "ok\n";
