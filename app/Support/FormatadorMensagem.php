<?php

namespace App\Support;

final class FormatadorMensagem
{
    public static function paraHtml(?string $texto): string
    {
        $escapado = e((string) $texto);
        $comQuebras = nl2br($escapado, false);

        $resultado = preg_replace_callback(
            '/https:\/\/[^\s<]+|http:\/\/[^\s<]+/i',
            function (array $match): string {
                $bruto = $match[0];
                $url = rtrim($bruto, '.,;:!?)');
                $resto = strlen($bruto) > strlen($url) ? substr($bruto, strlen($url)) : '';
                $decodificada = html_entity_decode($url, ENT_QUOTES, 'UTF-8');

                $href = e($decodificada);

                return '<a href="'.$href.'" target="_blank" rel="noopener noreferrer">'.$url.'</a>'.$resto;
            },
            $comQuebras
        );

        return is_string($resultado) ? $resultado : $comQuebras;
    }
}
