<?php

use App\Models\Mensagem;
use App\Models\User;
use App\Services\ServicoAnexo;
use App\Services\ServicoConversa;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (DB::getDriverName() !== 'sqlite') {
    fwrite(STDERR, 'Abortado: o seed manual exige SQLite isolado, não '.DB::getDriverName().".\n");
    exit(1);
}

$database = (string) config('database.connections.sqlite.database');

if ($database === '' || ! str_contains($database, 'tests/e2e/chat-e2e.sqlite')) {
    fwrite(STDERR, "Abortado: banco inesperado ({$database}).\n");
    exit(1);
}

$anexosRoot = (string) config('filesystems.disks.anexos.root');

if ($anexosRoot === '' || ! str_contains($anexosRoot, 'tests/e2e/storage/anexos')) {
    fwrite(STDERR, "Abortado: disco de anexos inesperado ({$anexosRoot}).\n");
    exit(1);
}

DB::statement('PRAGMA journal_mode=WAL;');

$fixtures = __DIR__.'/storage/manuais';
@mkdir($fixtures, 0777, true);
@mkdir($anexosRoot, 0777, true);

$escreverPng = function (string $caminho, int $largura, int $altura, int $r, int $g, int $b): void {
    if (! function_exists('imagecreatetruecolor')) {
        throw new RuntimeException('A extensão GD é necessária para gerar as imagens de teste.');
    }

    $imagem = imagecreatetruecolor($largura, $altura);
    $cor = imagecolorallocate($imagem, $r, $g, $b);
    imagefill($imagem, 0, 0, $cor);
    imagepng($imagem, $caminho, 6);
    imagedestroy($imagem);
};

$pngOk = $fixtures.'/anexo-ok.png';
$jpgOk = $fixtures.'/anexo-ok.jpg';
$pngGrande = $fixtures.'/anexo-grande.png';
$gifInvalido = $fixtures.'/anexo-invalido.gif';
$txtInvalido = $fixtures.'/anexo-invalido.txt';

$escreverPng($pngOk, 320, 200, 67, 95, 122);

$jpeg = imagecreatefrompng($pngOk);
imagejpeg($jpeg, $jpgOk, 90);
imagedestroy($jpeg);

$canvasGrande = imagecreatetruecolor(1600, 1600);
$fundoGrande = imagecolorallocate($canvasGrande, 40, 60, 80);
imagefill($canvasGrande, 0, 0, $fundoGrande);
imagepng($canvasGrande, $pngGrande, 0);
imagedestroy($canvasGrande);

if (filesize($pngGrande) < 2_100_000) {
    file_put_contents($pngGrande, file_get_contents($pngGrande).str_repeat("\0", 2_100_000 - (int) filesize($pngGrande)));
}

file_put_contents($gifInvalido, 'GIF89a'.str_repeat("\x00", 64));
file_put_contents($txtInvalido, "isto nao e uma imagem\n");

$senha = Hash::make('password');
$agora = Carbon::parse('2026-09-19 12:00:00');

$criar = function (string $nome, string $email) use ($senha): User {
    return User::query()->create([
        'name' => $nome,
        'email' => $email,
        'password' => $senha,
        'email_verified_at' => now(),
    ]);
};

$a = $criar('Ana Manual', 'ana.manual@example.com');
$b = $criar('Bruno Manual', 'bruno.manual@example.com');
$c = $criar('Carla Manual', 'carla.manual@example.com');
$d = $criar('Davi Manual', 'davi.manual@example.com');
$e = $criar('Eva Manual', 'eva.manual@example.com');
$f = $criar('Descartavel Manual', 'descartavel.manual@example.com');

$conversas = $app->make(ServicoConversa::class);
$ab = $conversas->individualEntre($a, $b);

foreach (range(1, 110) as $indice) {
    $deA = $indice % 2 === 1;
    Mensagem::query()->create([
        'conversa_id' => $ab->id,
        'remetente_id' => $deA ? $a->id : $b->id,
        'destinatario_id' => $deA ? $b->id : $a->id,
        'conteudo' => 'Histórico A-B '.$indice,
        'versao' => $indice,
        'created_at' => $agora->copy()->addMinutes($indice),
        'updated_at' => $agora->copy()->addMinutes($indice),
    ]);
}

$disco = Storage::disk(ServicoAnexo::DISCO);
$nomeAnexo = Str::uuid()->toString().'.png';
$disco->put($nomeAnexo, file_get_contents($pngOk));

$anexo = Mensagem::query()->create([
    'conversa_id' => $ab->id,
    'remetente_id' => $a->id,
    'destinatario_id' => $b->id,
    'conteudo' => '',
    'versao' => 111,
    'anexo_caminho' => $nomeAnexo,
    'anexo_mime' => 'image/png',
    'anexo_tamanho' => (int) filesize($pngOk),
    'created_at' => $agora->copy()->addMinutes(111),
    'updated_at' => $agora->copy()->addMinutes(111),
]);

$ultima = Mensagem::query()->where('conversa_id', $ab->id)->orderByDesc('id')->first();
$ab->forceFill([
    'versao' => 111,
    'ultima_mensagem_id' => $ultima?->id,
    'ultima_mensagem_em' => $ultima?->created_at,
])->save();

$grupo = $conversas->criarGrupo($a, 'Grupo ABC', collect([$b, $c]));

Mensagem::query()->create([
    'conversa_id' => $grupo->id,
    'remetente_id' => $a->id,
    'destinatario_id' => null,
    'conteudo' => 'Bem-vindos ao Grupo ABC',
    'versao' => 1,
    'created_at' => $agora->copy()->addMinutes(120),
    'updated_at' => $agora->copy()->addMinutes(120),
]);

$grupo->forceFill([
    'versao' => 1,
    'ultima_mensagem_id' => Mensagem::query()->where('conversa_id', $grupo->id)->orderByDesc('id')->value('id'),
    'ultima_mensagem_em' => $agora->copy()->addMinutes(120),
])->save();

$linhas = [
    'Ambiente isolado de validação manual',
    'URL: http://127.0.0.1:8002',
    'Senha de todas as contas seed: password',
    'Prefixo Pusher: '.prefixo_canal_broadcast(),
    'SQLite: '.$database,
    'Anexos: '.$anexosRoot,
    '',
    'A  id='.$a->id.'  Ana Manual            ana.manual@example.com',
    'B  id='.$b->id.'  Bruno Manual          bruno.manual@example.com',
    'C  id='.$c->id.'  Carla Manual          carla.manual@example.com',
    'D  id='.$d->id.'  Davi Manual           davi.manual@example.com   (fora do grupo)',
    'E  id='.$e->id.'  Eva Manual            eva.manual@example.com    (para adicionar ao grupo / perfil)',
    'F  id='.$f->id.'  Descartavel Manual    descartavel.manual@example.com',
    '',
    'Conversa vazia (A–C, sem mensagens e sem conversa criada): /?contato='.$c->id,
    'Histórico paginado (A–B, 110 textos + 1 imagem): /?contato='.$b->id,
    'Grupo ABC (A criadora, B e C membros): /?grupo='.$grupo->id,
    'Anexo pré-carregado (A→B): /mensagens/'.$anexo->id.'/anexo',
    'ID da mensagem com anexo: '.$anexo->id,
    'ID da conversa A–B: '.$ab->id,
    'ID do grupo ABC: '.$grupo->id,
    '',
    'Imagens de teste em '.$fixtures,
    '  anexo-ok.png / anexo-ok.jpg  — JPEG/PNG válidos (320×200)',
    '  anexo-grande.png             — maior que 2 MB',
    '  anexo-invalido.gif           — formato recusado',
    '  anexo-invalido.txt           — não é imagem',
];

file_put_contents($fixtures.'/ambiente.txt', implode("\n", $linhas)."\n");

echo implode("\n", $linhas)."\n";
