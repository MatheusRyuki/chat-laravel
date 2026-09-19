#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

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
export PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-8}"
export ANEXOS_PATH="$ROOT/tests/e2e/storage/anexos"
export FILESYSTEM_DISK=local

mkdir -p "$ANEXOS_PATH" "$SESSION_FILES"
rm -f "$DB_DATABASE"
touch "$DB_DATABASE"

php artisan migrate --force --no-interaction >/dev/null
php "$ROOT/tests/e2e/seed.php"

exec php artisan serve --host=127.0.0.1 --port=8002 --env=local --no-reload
