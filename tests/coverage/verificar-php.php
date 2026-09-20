<?php

$arquivo = __DIR__.'/../../test-results/coverage/php.xml';
if (! is_file($arquivo)) {
    fwrite(STDERR, "Relatório PHP ausente. Execute com Xdebug em modo coverage.\n");
    exit(1);
}
$relatorio = simplexml_load_file($arquivo);
$metricas = $relatorio->project->metrics;
$total = (int) $metricas['statements'];
$cobertas = (int) $metricas['coveredstatements'];
if ($total === 0 || $cobertas !== $total) {
    fwrite(STDERR, "Cobertura PHP insuficiente: {$cobertas}/{$total} linhas; exigido 100% de app/.\n");
    exit(1);
}
fwrite(STDOUT, "Cobertura PHP: {$cobertas}/{$total} linhas (100% de app/).\n");
