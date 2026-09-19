<?php

use Illuminate\Support\Carbon;

if (! function_exists('formatar_horario_mensagem')) {
    function formatar_horario_mensagem(?DateTimeInterface $quando): string
    {
        if ($quando === null) {
            return '';
        }

        $fuso = config('app.timezone');
        $momento = Carbon::parse($quando)->timezone($fuso);
        $hoje = now()->timezone($fuso)->startOfDay();
        $hora = $momento->format('H:i');

        if ($momento->copy()->startOfDay()->equalTo($hoje)) {
            return $hora;
        }

        if ($momento->year === $hoje->year) {
            return $momento->format('d/m').', '.$hora;
        }

        return $momento->format('d/m/Y').', '.$hora;
    }
}

if (! function_exists('avatar_data_uri')) {
    function avatar_data_uri(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '?', 0, 1);
        $second = mb_substr($parts[1] ?? '', 0, 1);
        $initials = mb_strtoupper($first.$second);

        $svg = sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80" viewBox="0 0 80 80"><rect width="80" height="80" rx="40" fill="%s"/><text x="50%%" y="54%%" text-anchor="middle" dominant-baseline="middle" fill="#ffffff" font-family="sans-serif" font-size="28" font-weight="600">%s</text></svg>',
            '#34495e',
            htmlspecialchars($initials, ENT_QUOTES | ENT_XML1, 'UTF-8')
        );

        return 'data:image/svg+xml;charset=UTF-8,'.rawurlencode($svg);
    }
}
