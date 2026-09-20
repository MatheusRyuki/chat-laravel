#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PID_FILE="$ROOT/tests/e2e/storage/manual-serve.pid"
PORT=8002

if [[ ! -f "$PID_FILE" ]]; then
    echo "Nenhum PID do servidor manual em $PID_FILE."
    echo "A porta ${PORT} não será encerrada às cegas (o app em :8000 permanece)."
    exit 0
fi

PID="$(cat "$PID_FILE" 2>/dev/null || true)"

if [[ -z "$PID" ]] || ! kill -0 "$PID" 2>/dev/null; then
    rm -f "$PID_FILE"
    echo "Processo do servidor manual já não estava ativo."
    exit 0
fi

CMDLINE="$(ps -p "$PID" -o args= 2>/dev/null || true)"

if [[ "$CMDLINE" != *"--port=${PORT}"* && "$CMDLINE" != *"--port ${PORT}"* ]]; then
    echo "Abortado: o PID ${PID} não parece ser o artisan serve da porta ${PORT}." >&2
    echo "Linha de comando: ${CMDLINE}" >&2
    exit 1
fi

kill "$PID" 2>/dev/null || true

for _ in $(seq 1 20); do
    if ! kill -0 "$PID" 2>/dev/null; then
        rm -f "$PID_FILE"
        echo "Servidor isolado em http://127.0.0.1:${PORT} encerrado."
        echo "O app em :8000 não foi alterado."
        exit 0
    fi
    sleep 0.2
done

echo "O processo ${PID} não encerrou a tempo; não será forçado." >&2
exit 1
