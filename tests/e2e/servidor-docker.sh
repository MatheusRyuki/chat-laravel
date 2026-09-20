#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
NOME="chat-e2e-$$"
encerrar() { docker stop "$NOME" >/dev/null 2>&1 || true; }
trap encerrar EXIT INT TERM
docker run --rm --name "$NOME" \
    -p 127.0.0.1:18002:18002 \
    -v "$ROOT:/var/www/html" -w /var/www/html -u "$(id -u):$(id -g)" \
    -e CHAT_E2E_DOCKER=1 --entrypoint bash chat-app:8.5 tests/e2e/iniciar.sh &
wait $!
