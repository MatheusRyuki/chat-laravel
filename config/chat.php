<?php

return [

    'historico_janela' => 50,

    'digitacao_expira_em' => 3,

    'digitacao_intervalo_segundos' => 2,

    'anexo_maximo_kb' => 2048,

    'prefixo_canal' => (string) env('CHAT_PREFIXO_CANAL', ''),

    'e2e' => filter_var(env('CHAT_E2E', false), FILTER_VALIDATE_BOOLEAN),

];
