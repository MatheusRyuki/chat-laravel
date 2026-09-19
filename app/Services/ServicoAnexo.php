<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ServicoAnexo
{
    public const DISCO = 'anexos';

    /**
     * @return array{caminho: string, mime: string, tamanho: int}
     */
    public function guardar(UploadedFile $arquivo): array
    {
        $extensao = $this->extensaoSegura($arquivo);
        $nome = Str::uuid()->toString().'.'.$extensao;
        $caminho = $arquivo->storeAs('', $nome, self::DISCO);

        if (! is_string($caminho) || $caminho === '') {
            throw new \RuntimeException('Não foi possível armazenar o anexo.');
        }

        return [
            'caminho' => $caminho,
            'mime' => (string) ($arquivo->getMimeType() ?: 'application/octet-stream'),
            'tamanho' => (int) $arquivo->getSize(),
        ];
    }

    public function excluir(?string $caminho): void
    {
        if (! filled($caminho)) {
            return;
        }

        try {
            if (Storage::disk(self::DISCO)->exists($caminho)) {
                Storage::disk(self::DISCO)->delete($caminho);
            }
        } catch (Throwable $e) {
            Log::warning('Falha ao remover anexo físico.', [
                'caminho' => $caminho,
                'tipo' => $e::class,
                'erro' => $e->getMessage(),
            ]);
        }
    }

    public function caminhoAbsoluto(string $caminho): string
    {
        return Storage::disk(self::DISCO)->path($caminho);
    }

    public function existe(string $caminho): bool
    {
        return Storage::disk(self::DISCO)->exists($caminho);
    }

    private function extensaoSegura(UploadedFile $arquivo): string
    {
        $mime = (string) $arquivo->getMimeType();

        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => throw new \InvalidArgumentException('Formato de imagem não suportado.'),
        };
    }
}
