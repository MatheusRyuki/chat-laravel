#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$ROOT"

set -a
# shellcheck disable=SC1091
source "$ROOT/.env"
set +a

if [[ "${DB_DATABASE}" != "chat" ]]; then
    echo "Recusando: DB_DATABASE do .env não é chat (é ${DB_DATABASE})." >&2
    exit 1
fi

DESCARTAVEL="${1:-chat_e2e_migracoes}"
LIMPA="${2:-chat_e2e_limpa}"

if [[ "$DESCARTAVEL" == "chat" || "$LIMPA" == "chat" ]]; then
    echo "Recusando: não usar o banco chat." >&2
    exit 1
fi

if [[ ! "$DESCARTAVEL" =~ ^[a-z0-9_]+$ || ! "$LIMPA" =~ ^[a-z0-9_]+$ ]]; then
    echo "Recusando: nomes de banco inválidos." >&2
    exit 1
fi

sql_root() {
    ./vendor/bin/sail exec -T mysql bash -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" --protocol=tcp -h127.0.0.1 -N -s'
}

sql_db() {
    local db="$1"
    ./vendor/bin/sail exec -T mysql bash -c 'mysql -uchat -p"$MYSQL_PASSWORD" --protocol=tcp -h127.0.0.1 -N -s --database='"$db"
}

echo "== Criando bases descartáveis $DESCARTAVEL e $LIMPA"
sql_root <<SQL
DROP DATABASE IF EXISTS ${DESCARTAVEL};
DROP DATABASE IF EXISTS ${LIMPA};
CREATE DATABASE ${DESCARTAVEL} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE ${LIMPA} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON ${DESCARTAVEL}.* TO 'chat'@'%';
GRANT ALL PRIVILEGES ON ${LIMPA}.* TO 'chat'@'%';
FLUSH PRIVILEGES;
SQL

echo "== Atualização incremental a partir do esquema anterior"
./vendor/bin/sail exec laravel.test env DB_DATABASE="$DESCARTAVEL" php artisan migrate --force --no-interaction \
    --path=database/migrations/0001_01_01_000000_create_users_table.php \
    --path=database/migrations/0001_01_01_000001_create_cache_table.php \
    --path=database/migrations/0001_01_01_000002_create_jobs_table.php \
    --path=database/migrations/2026_09_19_133912_create_mensagens_table.php

sql_db "$DESCARTAVEL" <<SQL
INSERT INTO users (name, email, password, created_at, updated_at) VALUES
('Ana Migracao MySQL', 'ana.migracao.mysql@example.com', 'x', '2026-01-15 10:20:30', '2026-01-15 10:20:30'),
('Bruno Migracao MySQL', 'bruno.migracao.mysql@example.com', 'x', '2026-01-15 10:20:30', '2026-01-15 10:20:30');
INSERT INTO mensagens (remetente_id, destinatario_id, conteudo, created_at, updated_at) VALUES
(1, 2, 'Texto conhecido da migracao incremental mysql', '2026-01-15 10:20:30', '2026-01-15 10:20:30');
SQL

./vendor/bin/sail exec laravel.test env DB_DATABASE="$DESCARTAVEL" php artisan migrate --force --no-interaction \
    --path=database/migrations/2026_09_19_173342_create_conversas_e_evolucao_do_chat_tables.php \
    --path=database/migrations/2026_09_19_174921_make_destinatario_id_nullable_on_mensagens.php

INCREMENTAL="$(sql_db "$DESCARTAVEL" <<SQL
SELECT CONCAT(id, '|', conteudo, '|', created_at, '|', IFNULL(conversa_id,'NULL')) FROM mensagens WHERE id=1;
SQL
)"
echo "incremental=$INCREMENTAL"
echo "$INCREMENTAL" | grep -q 'Texto conhecido da migracao incremental mysql'
echo "$INCREMENTAL" | grep -vq '|NULL$'

echo "== Instalação limpa"
./vendor/bin/sail exec laravel.test env DB_DATABASE="$LIMPA" php artisan migrate --force --no-interaction
LIMPA_OK="$(sql_db "$LIMPA" <<SQL
SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${LIMPA}' AND table_name IN ('conversas','conversa_participantes','bloqueios','mensagens');
SQL
)"
echo "tabelas_limpa=$LIMPA_OK"
[[ "$LIMPA_OK" == "4" ]]

echo "== Preservação do banco chat (somente leitura)"
sql_db chat <<SQL
SELECT CONCAT('users=', COUNT(*)) FROM users;
SELECT CONCAT('mensagens=', COUNT(*)) FROM mensagens;
SELECT CONCAT('conversas=', COUNT(*)) FROM conversas;
SELECT id, email FROM users ORDER BY id;
SQL

echo "== Encerrando bases descartáveis"
sql_root <<SQL
DROP DATABASE IF EXISTS ${DESCARTAVEL};
DROP DATABASE IF EXISTS ${LIMPA};
SQL

echo "ok"
