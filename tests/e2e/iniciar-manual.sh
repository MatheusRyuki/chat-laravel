#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

PID_FILE="$ROOT/tests/e2e/storage/manual-serve.pid"
LOG_FILE="$ROOT/tests/e2e/storage/manual-serve.log"
PORT=8002
RESET=0

if [[ "${1:-}" == "--reset" ]]; then
    RESET=1
elif [[ "${1:-}" != "" ]]; then
    echo "Uso: $0 [--reset]" >&2
    exit 1
fi

porta_em_uso() {
    local porta="$1"
    ss -ltn 2>/dev/null | grep -qE ":${porta}\\s" || return 1
}

pid_vivo() {
    local pid="$1"
    [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null
}

servidor_manual_ativo() {
    if [[ ! -f "$PID_FILE" ]]; then
        return 1
    fi

    local pid
    pid="$(cat "$PID_FILE" 2>/dev/null || true)"

    if ! pid_vivo "$pid"; then
        return 1
    fi

    if porta_em_uso "$PORT"; then
        return 0
    fi

    return 1
}

echo "== Isolamento =="

if porta_em_uso 8000; then
    echo "OK  http://127.0.0.1:8000 em uso (app real — não será tocado)."
else
    echo "AVISO  http://127.0.0.1:8000 não está escutando. O app real não foi iniciado por este script."
fi

if porta_em_uso 8001; then
    echo "INFO  a porta 8001 está em uso (não será tocada)."
fi

if servidor_manual_ativo; then
    if [[ "$RESET" -eq 1 ]]; then
        echo "O servidor isolado já está em http://127.0.0.1:${PORT}." >&2
        echo "Pare-o com tests/e2e/parar-manual.sh antes de usar --reset." >&2
        exit 1
    fi

    echo "OK  servidor isolado já está em http://127.0.0.1:${PORT} (dados preservados)."
    if [[ -f "$ROOT/tests/e2e/storage/manuais/ambiente.txt" ]]; then
        echo
        cat "$ROOT/tests/e2e/storage/manuais/ambiente.txt"
    fi
    exit 0
fi

if porta_em_uso "$PORT"; then
    echo "Abortado: a porta ${PORT} está em uso por outro processo." >&2
    echo "Não vou encerrar processos que não sejam o servidor manual." >&2
    exit 1
fi

set -a
# shellcheck disable=SC1091
source "$ROOT/.env"
set +a

export APP_ENV=local
export APP_DEBUG=true
export APP_URL=http://127.0.0.1:8002
export DB_CONNECTION=sqlite
export DB_DATABASE="$ROOT/tests/e2e/chat-e2e.sqlite"
unset DB_URL || true
export SESSION_DRIVER=file
export SESSION_COOKIE=chat_e2e_session
export SESSION_FILES="$ROOT/tests/e2e/storage/sessions"
export CACHE_STORE=array
export QUEUE_CONNECTION=sync
export CHAT_PREFIXO_CANAL=e2e-
export CHAT_E2E=1
export PHP_CLI_SERVER_WORKERS=1
export ANEXOS_PATH="$ROOT/tests/e2e/storage/anexos"
export FILESYSTEM_DISK=local
export MAIL_MAILER=log
export LOG_CHANNEL=stderr
export BROADCAST_CONNECTION="${BROADCAST_CONNECTION:-pusher}"

mkdir -p "$ANEXOS_PATH" "$SESSION_FILES" "$ROOT/tests/e2e/storage/manuais"

precisa_popular=0

if [[ "$RESET" -eq 1 ]]; then
    precisa_popular=1
elif [[ ! -s "$DB_DATABASE" || ! -f "$ROOT/tests/e2e/storage/manuais/ambiente.txt" ]]; then
    precisa_popular=1
fi

if [[ "$precisa_popular" -eq 1 ]]; then
    echo "Preparando SQLite isolado em $DB_DATABASE"
    rm -f "$DB_DATABASE" "${DB_DATABASE}-wal" "${DB_DATABASE}-shm"
    find "$ANEXOS_PATH" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
    find "$SESSION_FILES" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
    touch "$DB_DATABASE"
    php artisan migrate --force --no-interaction >/dev/null
    php "$ROOT/tests/e2e/seed-manual.php"
else
    echo "Reutilizando SQLite isolado existente (passe --reset para recomeçar os dados)."
fi

: > "$LOG_FILE"

nohup env PHP_CLI_SERVER_WORKERS=1 php artisan serve \
    --host=127.0.0.1 \
    --port="$PORT" \
    --env=local \
    --no-reload \
    >> "$LOG_FILE" 2>&1 &

echo $! > "$PID_FILE"

echo "Aguardando http://127.0.0.1:${PORT} …"

for _ in $(seq 1 30); do
    if curl -fsS -o /dev/null "http://127.0.0.1:${PORT}/login"; then
        echo "OK  servidor isolado em http://127.0.0.1:${PORT}"
        echo "Log: $LOG_FILE"
        echo "PID: $(cat "$PID_FILE")"
        if [[ -f "$ROOT/tests/e2e/storage/manuais/ambiente.txt" ]]; then
            echo
            cat "$ROOT/tests/e2e/storage/manuais/ambiente.txt"
        fi
        exit 0
    fi
    sleep 0.3
done

echo "Falha ao subir o servidor isolado. Veja $LOG_FILE" >&2
exit 1
